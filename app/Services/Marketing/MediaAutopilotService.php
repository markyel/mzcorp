<?php

namespace App\Services\Marketing;

use App\Models\MediaChannel;
use App\Models\MediaPublication;
use App\Models\MediaTopic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    private const LAST_RUN_KEY = 'media:autopilot:last-run';

    public function __construct(
        private readonly MediaMaterialService $materials,
        private readonly MediaPublisherService $publisher,
        private readonly \App\Services\Calendar\RussianWorkingDayService $calendar,
    ) {}

    /**
     * @return array{drafted: int, published: int, skipped: array<int, string>}
     */
    public function run(bool $publish = true): array
    {
        $drafted = 0;
        $published = 0;
        $skipped = [];

        // В выходные и праздники не публикуем — выпуски растянуты на рабочую неделю.
        if (! $this->calendar->isBusinessDay(now())) {
            $skipped[] = 'нерабочий день — выпуски ждут ближайшего рабочего';
            $this->remember($publish, 0, 0, $skipped);

            return ['drafted' => 0, 'published' => 0, 'skipped' => $skipped];
        }

        // Одна тема в день (config): сначала тема своего дня недели, потом
        // самая просроченная. Остальные ждут следующего рабочего дня — раньше
        // все созревшие темы уходили одним прогоном (29.09: три поста подряд).
        $topics = self::queueFor(
            MediaTopic::query()->active()->whereNotNull('cadence_days')->get()->filter(fn (MediaTopic $t) => $t->isDue()),
            now(),
        );
        $perDay = max(1, (int) config('services.marketing.autopilot_topics_per_day', 1));
        $taken = 0;

        foreach ($topics as $topic) {
            $channels = $this->channelsFor($topic);
            // Тема только для RSS-ленты (обзор недели) слот дня не занимает:
            // её не видят подписчики соцсетей, а ограничение «одна тема в день»
            // придумано против трёх постов подряд в одной ленте.
            $slotFree = self::slotFree($channels);
            if (! $slotFree && $taken >= $perDay) {
                $skipped[] = $topic->title.': перенесено на следующий рабочий день — в день выходит '.$perDay.' '.($perDay === 1 ? 'тема' : 'темы');

                continue;
            }
            $draftedBefore = $drafted;
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

                // Срок темы сдвигает сам публикатор — один раз на выпуск, сколько
                // бы автоканалов у темы ни было.
                $out = $this->publisher->publish($res['publication']);
                if ($out['ok']) {
                    $published++;
                } else {
                    $skipped[] = $topic->title.' → '.$channel->name.': '.$out['message'];
                }
            }

            // Слот дня занимает тема, по которой что-то сделано; тема, чьи
            // черновики ещё ждут человека, место следующей не отнимает.
            if ($drafted > $draftedBefore && ! $slotFree) {
                $taken++;
            }
        }

        Log::info('MediaAutopilot: run finished', [
            'drafted' => $drafted,
            'published' => $published,
            'skipped' => count($skipped),
        ]);

        $this->remember($publish, $drafted, $published, $skipped);

        return ['drafted' => $drafted, 'published' => $published, 'skipped' => $skipped];
    }

    /**
     * Итог прогона показываем в разделе: без него «почему сегодня ничего
     * не вышло» можно было узнать только из логов сервера.
     *
     * @param  list<string>  $skipped
     */
    private function remember(bool $publish, int $drafted, int $published, array $skipped): void
    {
        Cache::put(self::LAST_RUN_KEY, [
            'at' => now()->toIso8601String(),
            'publish' => $publish,
            'drafted' => $drafted,
            'published' => $published,
            'skipped' => array_values(array_unique($skipped)),
        ], now()->addDays(30));
    }

    /**
     * Порядок созревших тем на день: тема своего дня недели первой, дальше
     * самая просроченная, при равенстве — по названию.
     *
     * @param  Collection<int, MediaTopic>  $topics
     * @param  array<int, Carbon>  $dueById  срок темы, если он отличается от next_due_on (прогноз)
     * @return Collection<int, MediaTopic>
     */
    public static function queueFor(Collection $topics, Carbon $day, array $dueById = []): Collection
    {
        return $topics->sortBy(fn (MediaTopic $t) => [
            (int) $t->publish_weekday === $day->isoWeekday() ? 0 : 1,
            ($dueById[$t->id] ?? $t->next_due_on)?->format('Y-m-d') ?? '9999',
            $t->title,
        ])->values();
    }

    /** @return array{at: string, publish: bool, drafted: int, published: int, skipped: list<string>}|null */
    public function lastRun(): ?array
    {
        $run = Cache::get(self::LAST_RUN_KEY);

        return is_array($run) ? $run : null;
    }

    /** Время ежедневного прогона, «ЧЧ:ММ». */
    public static function runAt(): string
    {
        return (string) config('services.marketing.autopilot_at', '09:15');
    }

    /**
     * Что конвейер сделает в ближайшие $days дней: по дням — какая тема, в какой
     * канал и что с ней будет (уйдёт сама / ляжет в черновик / не выйдет и почему).
     *
     * Это прогноз по тем же правилам, что и run(): те же каналы темы, та же
     * проверка «прошлый материал ещё в работе». Причины «не выйдет» считаются
     * только для ближайшего выпуска — дальше они сами по себе не доживут.
     *
     * @return list<array{date: Carbon, topic: MediaTopic, overdue_since: ?Carbon, data_driven: bool,
     *     note: ?string, entries: list<array{channel: MediaChannel, mode: string, mirrors: list<string>, blocker: ?string}>}>
     */
    public function upcoming(int $days = 28): array
    {
        [$hour, $minute] = array_map('intval', explode(':', self::runAt()) + [1 => 0]);
        $todayRun = now()->setTime($hour, $minute);
        // Сегодняшний прогон уже прошёл — ближайший завтра.
        $day = now()->gte($todayRun) ? now()->addDay()->startOfDay() : now()->startOfDay();
        $until = now()->startOfDay()->addDays($days);
        $perDay = max(1, (int) config('services.marketing.autopilot_topics_per_day', 1));

        $mirrors = MediaChannel::query()->active()->whereNotNull('mirror_of_channel_id')->get()
            ->groupBy('mirror_of_channel_id');
        $dataDriven = app(MediaDataService::class);

        $topics = MediaTopic::query()->active()->whereNotNull('cadence_days')->whereNotNull('next_due_on')->get()->keyBy('id');
        $channels = $topics->map(fn (MediaTopic $t) => $this->channelsFor($t));
        // Срок каждой темы по ходу симуляции; первый выпуск — с проверкой препятствий.
        $due = $topics->map(fn (MediaTopic $t) => $t->next_due_on->copy()->startOfDay())->all();
        $first = $topics->map(fn () => true)->all();

        // Та же очередь, что у run(): рабочие дни, N тем в день, своя тема
        // дня первой, потом самая просроченная.
        $out = [];
        for (; $day->lte($until); $day = $day->copy()->addDay()) {
            if (! $this->calendar->isBusinessDay($day)) {
                continue;
            }
            $ready = $topics->filter(fn (MediaTopic $t) => $due[$t->id]->lte($day));
            $queue = self::queueFor($ready, $day, $due);
            $todays = $queue->reject(fn (MediaTopic $t) => self::slotFree($channels[$t->id]))->take($perDay)
                ->merge($queue->filter(fn (MediaTopic $t) => self::slotFree($channels[$t->id])));
            foreach ($todays as $topic) {
                $entries = [];
                foreach ($channels[$topic->id] as $channel) {
                    $entries[] = [
                        'channel' => $channel,
                        'mode' => $this->modeFor($channel),
                        'mirrors' => ($mirrors[$channel->id] ?? collect())->pluck('name')->all(),
                        'blocker' => $first[$topic->id] ? $this->blockerFor($topic, $channel) : null,
                    ];
                }

                $out[] = [
                    'date' => $day->copy(),
                    'topic' => $topic,
                    'overdue_since' => $due[$topic->id]->lt($day) ? $due[$topic->id]->copy() : null,
                    'data_driven' => $dataDriven->isDataDriven($topic),
                    'note' => $channels[$topic->id]->isEmpty()
                        ? 'не выбран канал — сделайте первый материал руками или включите автопубликацию в канале'
                        : null,
                    'entries' => $entries,
                ];

                $first[$topic->id] = false;
                $due[$topic->id] = $topic->nextDueAfter($day, $due[$topic->id]);
            }
        }

        return $out;
    }

    /** Тема идёт только в RSS-ленту — дневной слот соцсетей не занимает. */
    public static function slotFree(Collection $channels): bool
    {
        return $channels->isNotEmpty() && $channels->every(fn (MediaChannel $c) => $c->kind === 'rss');
    }

    /** publish — уйдёт сама; draft — ляжет в черновик и ждёт человека. */
    private function modeFor(MediaChannel $channel): string
    {
        return $channel->auto_publish && $channel->isPostable() ? 'publish' : 'draft';
    }

    /** Почему ближайший выпуск в этот канал не выйдет; null — препятствий не видно. */
    private function blockerFor(MediaTopic $topic, MediaChannel $channel): ?string
    {
        $pending = MediaPublication::query()
            ->where('media_topic_id', $topic->id)
            ->where('media_channel_id', $channel->id)
            ->whereNotIn('status', ['published', 'rejected'])
            ->orderBy('id')
            ->first(['id', 'title', 'status', 'created_at']);
        if ($pending !== null) {
            return 'новый материал не напишется: ждёт «'.Str::limit((string) $pending->title, 60)
                .'» от '.$pending->created_at?->format('d.m').' — опубликуйте или отклоните его';
        }

        if ($this->modeFor($channel) === 'publish' && ! $channel->isConnected()) {
            return 'у канала не заполнен доступ — материал ляжет в черновик';
        }

        return null;
    }

    /**
     * Каналы темы. Явной привязки «тема ↔ канал» нет: тему ведут там, где по
     * ней уже публиковались, а если публикаций ещё не было — во всех активных
     * каналах с автопубликацией. Так новая тема не молчит, а старая не
     * расползается по площадкам, где её не ведут.
     *
     * @return Collection<int, MediaChannel>
     */
    public function channelsFor(MediaTopic $topic): Collection
    {
        // RSS-лента и обзор недели — пара: обзор идёт только в ленту, а
        // обычные посты (подборки, советы) в ленту не попадают — редакциям
        // порталов нужна одна статья в неделю, а не каждый пост соцсетей.
        if ($topic->source === 'weekly_roundup') {
            return MediaChannel::query()->active()->where('kind', 'rss')->get();
        }

        $usedIds = MediaPublication::query()
            ->where('media_topic_id', $topic->id)
            ->whereNotNull('media_channel_id')
            ->distinct()
            ->pluck('media_channel_id');

        // Зеркала пропускаем: Дзен забирает пост из Telegram сам, и свой
        // материал ему писать не нужно — вышло бы два разных поста об одном.
        if ($usedIds->isNotEmpty()) {
            return MediaChannel::query()->active()->whereNull('mirror_of_channel_id')
                ->where('kind', '!=', 'rss')
                ->whereIn('id', $usedIds)->get();
        }

        return MediaChannel::query()->active()->whereNull('mirror_of_channel_id')
            ->where('kind', '!=', 'rss')
            ->where('auto_publish', true)->get();
    }
}
