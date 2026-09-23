<?php

namespace App\Services\Marketing;

use App\Enums\MediaProfileFacet;
use App\Models\ClientFeedback;
use App\Models\Competitor;
use App\Models\CompetitorInsight;
use App\Models\MediaProfileEntry;
use App\Models\User;
use App\Prompts\Marketing\CompetitorInsightPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Разбор отзывов о конкуренте: чем мы лучше и чем мы хуже.
 *
 * Выводы модели — кандидаты, а не записи. В медиапрофиль и в обратную связь
 * они попадают только по клику человека: реклама, в которую автоматика
 * дописала утверждение о компании, дороже любой экономии времени.
 *
 * Вывод без цитаты из отзыва отбрасываем — так же, как в проверке материалов:
 * непроверяемое утверждение хуже, чем его отсутствие.
 */
class CompetitorInsightService
{
    /** Больше отзывов в один разбор не отдаём: длинный список модель начинает пересказывать. */
    public const MAX_REVIEWS = 60;

    public function __construct(private readonly OpenAIChatService $openai) {}

    /**
     * @return array{ok: bool, count: int, message: string}
     */
    public function extract(Competitor $competitor, ?User $author): array
    {
        $reviews = $competitor->reviewsBrief(self::MAX_REVIEWS);
        if (trim($reviews) === '') {
            return ['ok' => false, 'count' => 0, 'message' => 'У конкурента нет отзывов — разбирать нечего.'];
        }

        $profile = MediaProfileEntry::asBrief();
        if ($profile === '') {
            return ['ok' => false, 'count' => 0, 'message' => 'Медиапрофиль пуст — не с чем сравнивать.'];
        }

        $model = (string) config('services.openai.media_profile_model', 'gpt-4o');

        try {
            $response = $this->openai->chat(
                [
                    ['role' => 'system', 'content' => CompetitorInsightPrompt::systemMessage()],
                    ['role' => 'user', 'content' => CompetitorInsightPrompt::userMessage(
                        $profile,
                        $competitor->name,
                        $reviews,
                        $competitor->notes,
                    )],
                ],
                $model,
                ['response_format' => ['type' => 'json_object'], 'temperature' => 0.2],
            );
        } catch (\Throwable $e) {
            Log::error('CompetitorInsightService: модель не ответила', [
                'competitor_id' => $competitor->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'count' => 0, 'message' => 'Разбор не удался: '.$e->getMessage()];
        }

        $parsed = json_decode((string) ($response['content'] ?? ''), true);
        if (! is_array($parsed)) {
            return ['ok' => false, 'count' => 0, 'message' => 'Модель ответила не по форме — попробуйте ещё раз.'];
        }

        $known = $competitor->insights()->pluck('statement')
            ->map(fn ($s) => $this->fingerprint((string) $s))->all();

        $created = 0;
        foreach ([CompetitorInsight::KIND_ADVANTAGE => 'advantages', CompetitorInsight::KIND_WEAKNESS => 'weaknesses'] as $kind => $key) {
            foreach ($this->rows($parsed[$key] ?? []) as $row) {
                $statement = trim((string) ($row['statement'] ?? ''));
                $evidence = trim((string) ($row['evidence'] ?? ''));
                if ($statement === '' || $evidence === '') {
                    continue;
                }
                // Второй разбор не должен повторять то, что человек уже видел.
                if (in_array($this->fingerprint($statement), $known, true)) {
                    continue;
                }
                $known[] = $this->fingerprint($statement);

                $competitor->insights()->create([
                    'kind' => $kind,
                    'statement' => mb_substr($statement, 0, 500),
                    'evidence' => mb_substr($evidence, 0, 2000),
                    'facet' => $kind === CompetitorInsight::KIND_ADVANTAGE
                        ? (MediaProfileFacet::tryFrom((string) ($row['facet'] ?? ''))?->value ?? MediaProfileFacet::Positioning->value)
                        : null,
                    'topic' => $kind === CompetitorInsight::KIND_WEAKNESS
                        ? (trim((string) ($row['topic'] ?? '')) !== '' ? mb_substr(trim((string) $row['topic']), 0, 64) : null)
                        : null,
                    'status' => 'new',
                    'model' => $model,
                    'created_by_user_id' => $author?->id,
                ]);
                $created++;
            }
        }

        $summary = trim((string) ($parsed['summary'] ?? ''));

        return [
            'ok' => true,
            'count' => $created,
            'message' => $created === 0
                ? 'Нового в отзывах не нашлось — все выводы уже разобраны.'
                : 'Новых выводов: '.$created.($summary !== '' ? '. '.$summary : ''),
        ];
    }

    /**
     * Принять кандидата: «лучше» уходит в медиапрофиль, «хуже» — в обратную связь.
     *
     * @return array{ok: bool, message: string}
     */
    public function accept(CompetitorInsight $insight, ?User $user): array
    {
        if (! $insight->isNew()) {
            return ['ok' => false, 'message' => 'Этот вывод уже разобран.'];
        }

        if ($insight->isAdvantage()) {
            $entry = MediaProfileEntry::create([
                'facet' => MediaProfileFacet::tryFrom((string) $insight->facet)?->value ?? MediaProfileFacet::Positioning->value,
                'statement' => $insight->statement,
                'details' => $this->source($insight),
                'is_strict' => false,
                'is_active' => true,
                'position' => 0,
                'created_by_user_id' => $user?->id,
            ]);

            $insight->forceFill(['status' => 'accepted', 'media_profile_entry_id' => $entry->id])->save();

            return ['ok' => true, 'message' => 'Добавлено в медиапрофиль — рубрика «'.($entry->facet->label()).'».'];
        }

        $feedback = ClientFeedback::create([
            'source' => 'competitor',
            'source_url' => $insight->competitor?->platform_url,
            'client' => $insight->competitor?->name,
            'quote' => $insight->evidence !== null && $insight->evidence !== ''
                ? $insight->evidence
                : $insight->statement,
            'topic' => $insight->topic,
            'status' => 'new',
            'decision' => $insight->statement,
            'created_by_user_id' => $user?->id,
        ]);

        $insight->forceFill(['status' => 'accepted', 'client_feedback_id' => $feedback->id])->save();

        return ['ok' => true, 'message' => 'Отправлено в обратную связь — там ждёт решение.'];
    }

    public function dismiss(CompetitorInsight $insight): void
    {
        if ($insight->isNew()) {
            $insight->forceFill(['status' => 'dismissed'])->save();
        }
    }

    /** Откуда взялось утверждение — чтобы через полгода не гадать. */
    private function source(CompetitorInsight $insight): string
    {
        $name = $insight->competitor?->name ?? 'конкурент';

        return 'Из разбора отзывов: '.$name.'. Подтверждение: «'.mb_substr((string) $insight->evidence, 0, 300).'»';
    }

    /** @param mixed $rows @return array<int, array<string, mixed>> */
    private function rows($rows): array
    {
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** Грубое сравнение формулировок: модель редко повторяет вывод слово в слово. */
    private function fingerprint(string $statement): string
    {
        $s = mb_strtolower($statement);
        $s = preg_replace('~[^\p{L}\p{N}]+~u', ' ', $s) ?? $s;

        return trim(mb_substr((string) $s, 0, 60));
    }
}
