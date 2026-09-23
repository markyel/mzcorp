<?php

namespace App\Services\Marketing;

use App\Models\ClientFeedback;
use App\Models\Competitor;
use App\Models\MarketPosition;
use App\Models\MediaProfileEntry;
use App\Models\User;
use App\Prompts\Marketing\MarketPositionPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Позиция на рынке: все конкуренты сразу, а не по одному.
 *
 * Разбор по конкуренту отвечает «что у него лучше». Здесь другой вопрос:
 * что на этом рынке считается нормой, где мы реально впереди, где отстаём и
 * чего не хвалят ни у кого. Поэтому на вход идёт всё сразу — наша карточка,
 * чужие карточки, отзывы с обеих сторон, профиль и открытые претензии.
 *
 * Сводка хранится версиями вместе со снимком входных данных: позиция
 * меняется, и через квартал важно знать, из каких цифр сделан прошлый вывод.
 */
class MarketPositionService
{
    /** Отзывов на карточку в сводку — больше модель начинает пересказывать. */
    public const REVIEWS_PER_CARD = 25;

    public function __construct(private readonly OpenAIChatService $openai) {}

    /**
     * @return array{ok: bool, position: ?MarketPosition, message: string}
     */
    public function build(?User $author): array
    {
        $self = Competitor::query()->where('is_self', true)->first();
        $rivals = Competitor::query()->where('is_self', false)->active()->orderBy('name')->get();

        if ($rivals->isEmpty()) {
            return ['ok' => false, 'position' => null, 'message' => 'Конкурентов в разделе нет — сравнивать не с кем.'];
        }

        $profile = MediaProfileEntry::asBrief();
        if ($profile === '') {
            return ['ok' => false, 'position' => null, 'message' => 'Медиапрофиль пуст — нечем описать нашу сторону.'];
        }

        $us = $self !== null
            ? $this->card($self)
            : 'Своя карточка не заведена — сравнивать можно только по медиапрофилю.';

        $rivalsText = $rivals->map(fn (Competitor $c) => $this->card($c))->implode("\n\n");
        $complaints = $this->complaints();

        $model = (string) config('services.openai.media_profile_model', 'gpt-4o');
        $snapshot = "МЫ:\n".$us."\n\nКОНКУРЕНТЫ:\n".$rivalsText."\n\nПРЕТЕНЗИИ:\n".$complaints;

        try {
            $response = $this->openai->chat(
                [
                    ['role' => 'system', 'content' => MarketPositionPrompt::systemMessage()],
                    ['role' => 'user', 'content' => MarketPositionPrompt::userMessage($profile, $us, $rivalsText, $complaints)],
                ],
                $model,
                ['temperature' => 0.3],
            );
        } catch (\Throwable $e) {
            Log::error('MarketPositionService: модель не ответила', ['error' => $e->getMessage()]);

            return ['ok' => false, 'position' => null, 'message' => 'Сводка не собралась: '.$e->getMessage()];
        }

        $body = trim((string) ($response['content'] ?? ''));
        if ($body === '') {
            return ['ok' => false, 'position' => null, 'message' => 'Модель вернула пустой ответ — попробуйте ещё раз.'];
        }

        $position = MarketPosition::create([
            'body' => $body,
            'snapshot' => $snapshot,
            'competitors_count' => $rivals->count(),
            'reviews_count' => $rivals->sum(fn (Competitor $c) => $c->reviews()->count())
                + ($self?->reviews()->count() ?? 0),
            'model' => $model,
            'created_by_user_id' => $author?->id,
        ]);

        return [
            'ok' => true,
            'position' => $position,
            'message' => 'Сводка собрана: '.$position->competitors_count.' конкурентов, '
                .$position->reviews_count.' отзывов.',
        ];
    }

    public function latest(): ?MarketPosition
    {
        return MarketPosition::query()->with('author:id,name')->orderByDesc('id')->first();
    }

    /** Карточка текстом: витрина площадки плюс сами отзывы. */
    private function card(Competitor $competitor): string
    {
        $head = $competitor->name
            .($competitor->site ? ' ('.$competitor->site.')' : '')
            .' — '.$competitor->platformLabel().': '.($competitor->scoreLine() ?: 'оценок нет');

        $lines = [$head];
        if ($competitor->notes) {
            $lines[] = 'О компании: '.$competitor->notes;
        }

        $reviews = $competitor->reviewsBrief(self::REVIEWS_PER_CARD);
        $lines[] = $reviews !== '' ? 'Отзывы:'."\n".$reviews : 'Отзывов не собрано.';

        return implode("\n", $lines);
    }

    /** Открытые претензии к нам — их обязательно видеть рядом с чужими похвалами. */
    private function complaints(): string
    {
        return ClientFeedback::query()
            ->whereIn('status', ['new', 'in_progress'])
            ->orderBy('id')
            ->get()
            ->map(fn (ClientFeedback $f) => '— '.($f->topic ? '['.$f->topic.'] ' : '').trim($f->quote))
            ->implode("\n");
    }
}
