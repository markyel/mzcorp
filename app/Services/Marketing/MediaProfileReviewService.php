<?php

namespace App\Services\Marketing;

use App\Enums\MediaProfileFacet;
use App\Models\MediaProfileEntry;
use App\Models\MediaProfileReview;
use App\Models\User;
use App\Prompts\Marketing\ReviewMaterialPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Матрица: материал проходит через медиапрофиль.
 *
 * Профиль отдаётся модели как единственный источник требований, а результат
 * разбирается в две части: замечания с цитатами (их читает человек) и
 * переписанный текст (его он забирает). Разбор сохраняется вместе со снимком
 * профиля — через месяц иначе не понять, по каким правилам материал проверяли.
 */
class MediaProfileReviewService
{
    /** Материал длиннее — не отдаём модели целиком: обрежем и скажем об этом. */
    public const MAX_CHARS = 20000;

    public function __construct(private readonly OpenAIChatService $openai) {}

    /**
     * @return array{ok: bool, review: ?MediaProfileReview, message: string}
     */
    public function review(string $kind, string $material, ?string $title, ?User $author): array
    {
        $material = trim($material);
        if ($material === '') {
            return ['ok' => false, 'review' => null, 'message' => 'Нечего проверять — вставьте текст материала.'];
        }

        $profile = MediaProfileEntry::asBrief();
        if ($profile === '') {
            return [
                'ok' => false,
                'review' => null,
                'message' => 'Медиапрофиль пуст — сверять не с чем. Сначала заполните хотя бы пару утверждений.',
            ];
        }

        $truncated = mb_strlen($material) > self::MAX_CHARS;
        $model = (string) config('services.openai.media_profile_model', 'gpt-4o');

        try {
            $response = $this->openai->chat(
                [
                    ['role' => 'system', 'content' => ReviewMaterialPrompt::systemMessage()],
                    ['role' => 'user', 'content' => ReviewMaterialPrompt::userMessage(
                        $profile,
                        $kind,
                        mb_substr($material, 0, self::MAX_CHARS),
                    )],
                ],
                $model,
                ['response_format' => ['type' => 'json_object'], 'temperature' => 0.2],
            );
        } catch (\Throwable $e) {
            Log::error('MediaProfileReviewService: модель не ответила', ['error' => $e->getMessage()]);

            return ['ok' => false, 'review' => null, 'message' => 'Проверка не удалась: '.$e->getMessage()];
        }

        $parsed = json_decode((string) ($response['content'] ?? ''), true);
        if (! is_array($parsed)) {
            return ['ok' => false, 'review' => null, 'message' => 'Модель ответила не по форме — попробуйте ещё раз.'];
        }

        $review = MediaProfileReview::create([
            'kind' => array_key_exists($kind, MediaProfileReview::KINDS) ? $kind : 'other',
            'title' => $title !== null && trim($title) !== '' ? mb_substr(trim($title), 0, 255) : null,
            'source_text' => $material,
            'rewritten_text' => is_string($parsed['rewritten'] ?? null) ? $parsed['rewritten'] : null,
            'issues' => $this->cleanIssues($parsed['issues'] ?? [], $material),
            'profile_snapshot' => $profile,
            'model' => $model,
            'created_by_user_id' => $author?->id,
        ]);

        $summary = is_string($parsed['summary'] ?? null) ? trim($parsed['summary']) : '';

        return [
            'ok' => true,
            'review' => $review,
            'message' => trim(($truncated ? 'Материал длинный, проверена первая часть. ' : '').$summary),
        ];
    }

    /**
     * Оставляем только замечания с цитатой, которая ДЕЙСТВИТЕЛЬНО есть в
     * материале: выдуманная цитата означает, что модель разбирала не тот текст,
     * и доверять такому замечанию нельзя.
     *
     * @param  mixed  $raw
     * @return array<int, array<string, string>>
     */
    private function cleanIssues($raw, string $material): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $haystack = mb_strtolower(preg_replace('/\s+/u', ' ', $material) ?? $material);
        $out = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $quote = trim((string) ($item['quote'] ?? ''));
            $problem = trim((string) ($item['problem'] ?? ''));
            if ($problem === '') {
                continue;
            }
            if ($quote !== '') {
                $needle = mb_strtolower(preg_replace('/\s+/u', ' ', $quote) ?? $quote);
                if (! str_contains($haystack, $needle)) {
                    $quote = '';
                }
            }

            $facet = MediaProfileFacet::tryFrom((string) ($item['facet'] ?? ''));
            $out[] = [
                'facet' => $facet?->value ?? '',
                'facet_label' => $facet?->label() ?? '—',
                'severity' => ($item['severity'] ?? '') === 'strict' ? 'strict' : 'soft',
                'quote' => mb_substr($quote, 0, 300),
                'problem' => mb_substr($problem, 0, 300),
                'fix' => mb_substr(trim((string) ($item['fix'] ?? '')), 0, 300),
            ];
        }

        return $out;
    }
}
