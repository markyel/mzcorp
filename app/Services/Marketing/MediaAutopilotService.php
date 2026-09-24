<?php

namespace App\Services\Marketing;

use App\Models\MediaChannel;
use App\Models\MediaPublication;
use App\Models\MediaTopic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Конвейер медиаплана: раз в день смотрит, каким темам пора, пишет черновики
 * и — там, где канал это разрешил, — публикует.
 *
 * Разделение намеренное. Черновик безопасен: его всегда можно выбросить,
 * поэтому генерация идёт сама по всем активным каналам темы. Публикация
 * необратима, поэтому уходит только в канал с включённой автопубликацией;
 * остальные материалы ложатся в «Черновик» и ждут человека.
 *
 * Тема считается отработанной по факту публикации — срок двигает не генерация,
 * а размещение: иначе тема, чей черновик никто не выпустил, тихо уехала бы на
 * неделю вперёд.
 */
class MediaAutopilotService
{
    /** Сколько черновиков делаем за один прогон: защита от расходов на модель. */
    public const MAX_DRAFTS_PER_RUN = 10;

    public function __construct(
        private readonly MediaMaterialService $materials,
        private readonly MediaPublisherService $publisher,
    ) {}

    /**
     * @return array{drafted: int, published: int, skipped: array<int, string>}
     */
    public function run(bool $publish = true): array
    {
        $drafted = 0;
        $published = 0;
        $skipped = [];

        $topics = MediaTopic::query()->active()->whereNotNull('cadence_days')->get()
            ->filter(fn (MediaTopic $t) => $t->isDue());

        foreach ($topics as $topic) {
            $channels = $this->channelsFor($topic);
            if ($channels->isEmpty()) {
                // Молча ничего не делать — худший вариант: тема «горит» в разделе,
                // а конвейер её игнорирует. Говорим, чего не хватает.
                $skipped[] = $topic->title.': не выбран канал — сделайте первый материал руками '
                    .'или включите автопубликацию в нужном канале';

                continue;
            }

            foreach ($channels as $channel) {
                if ($drafted >= self::MAX_DRAFTS_PER_RUN) {
                    $skipped[] = 'лимит черновиков за прогон исчерпан';
                    break 2;
                }

                // Уже есть неопубликованный материал по паре тема×канал — второй
                // не плодим: значит, прошлый ещё ждёт человека.
                $pending = MediaPublication::query()
                    ->where('media_topic_id', $topic->id)
                    ->where('media_channel_id', $channel->id)
                    ->whereNotIn('status', ['published', 'rejected'])
                    ->exists();
                if ($pending) {
                    $skipped[] = $topic->title.' → '.$channel->name.': прошлый материал ещё в работе';

                    continue;
                }

                $res = $this->materials->draft($topic, $channel, null);
                if (! $res['ok']) {
                    $skipped[] = $topic->title.' → '.$channel->name.': '.$res['message'];

                    continue;
                }
                $drafted++;

                if (! $publish || ! $channel->auto_publish) {
                    continue;
                }

                $out = $this->publisher->publish($res['publication']);
                if ($out['ok']) {
                    $published++;
                    $this->advance($topic);
                } else {
                    $skipped[] = $topic->title.' → '.$channel->name.': '.$out['message'];
                }
            }
        }

        Log::info('MediaAutopilot: run finished', [
            'drafted' => $drafted,
            'published' => $published,
            'skipped' => count($skipped),
        ]);

        return ['drafted' => $drafted, 'published' => $published, 'skipped' => $skipped];
    }

    /**
     * Каналы темы. Явной привязки «тема ↔ канал» нет: тему ведут там, где по
     * ней уже публиковались, а если публикаций ещё не было — во всех активных
     * каналах с автопубликацией. Так новая тема не молчит, а старая не
     * расползается по площадкам, где её не ведут.
     *
     * @return Collection<int, MediaChannel>
     */
    private function channelsFor(MediaTopic $topic): Collection
    {
        $usedIds = MediaPublication::query()
            ->where('media_topic_id', $topic->id)
            ->whereNotNull('media_channel_id')
            ->distinct()
            ->pluck('media_channel_id');

        if ($usedIds->isNotEmpty()) {
            return MediaChannel::query()->active()->whereIn('id', $usedIds)->get();
        }

        return MediaChannel::query()->active()->where('auto_publish', true)->get();
    }

    /** Следующий срок темы — от текущего, а не от сегодня: расписание не съезжает. */
    private function advance(MediaTopic $topic): void
    {
        $base = $topic->next_due_on && $topic->next_due_on->isFuture() ? $topic->next_due_on : now();
        $topic->forceFill([
            'next_due_on' => $base->copy()->addDays((int) $topic->cadence_days)->toDateString(),
        ])->save();
    }
}
