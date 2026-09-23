<?php

namespace App\Services\Direct;

use App\Models\DirectQueryReview;
use App\Models\DirectStat;
use App\Models\User;
use App\Prompts\Direct\JudgeSearchQueriesPrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Log;

/**
 * Минус-фразы по реальным поисковым запросам.
 *
 * Автотаргетинг подбирает запросы сам и подбирает широко: 23.09.2026 из 39
 * показов нашей кампании все пришли его подбором, а среди запросов — блоки
 * питания, поручни для ванной и материнские платы. Правилами такое не
 * отсечь: «поручень» есть и у нас, и в сантехнике. Поэтому запросы разбирает
 * модель, а минус-фразу в кампанию добавляет человек кнопкой — ошибка в
 * минус-слове молча выключает живой трафик, и заметить это по нулям нельзя.
 *
 * Автоматический режим (настройка `direct.negatives_auto`) применяет только
 * уверенные «чужие» вердикты с непустой минус-фразой.
 */
class DirectNegativeService
{
    /** Включён ли автоматический режим. */
    public const SETTING_AUTO = 'direct.negatives_auto';

    /** Сколько запросов отдаём модели за раз. */
    public const BATCH = 40;

    /** Сколько запросов разбираем за прогон. */
    public const MAX_PER_RUN = 200;

    /** Директ не принимает в кампанию больше стольких минус-фраз. */
    public const CAMPAIGN_LIMIT = 1000;

    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly DirectPublisherService $publisher,
        private readonly SettingsService $settings,
    ) {}

    public function autoEnabled(): bool
    {
        return (bool) $this->settings->get(self::SETTING_AUTO, false);
    }

    /**
     * Разобрать моделью запросы, которых мы ещё не видели.
     *
     * @return array{judged: int, foreign: int, error: ?string}
     */
    public function judge(int $days = 30): array
    {
        $known = DirectQueryReview::query()->pluck('query')->all();

        $fresh = DirectStat::query()
            ->where('kind', DirectStat::KIND_QUERY)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->when($known !== [], fn ($q) => $q->whereNotIn('name', $known))
            ->get()
            ->groupBy('name');

        if ($fresh->isEmpty()) {
            return ['judged' => 0, 'foreign' => 0, 'error' => null];
        }

        $judged = 0;
        $foreign = 0;
        $model = (string) config('services.openai.direct_query_model', 'gpt-4o-mini');

        foreach ($fresh->take(self::MAX_PER_RUN)->chunk(self::BATCH) as $chunk) {
            $queries = $chunk->keys()->all();
            $verdicts = $this->ask($queries, $model);
            if ($verdicts === null) {
                return ['judged' => $judged, 'foreign' => $foreign, 'error' => 'Модель не ответила — разбор отложен.'];
            }

            foreach ($chunk as $query => $rows) {
                $verdict = $verdicts[mb_strtolower($query)] ?? null;
                if ($verdict === null) {
                    continue;
                }
                DirectQueryReview::query()->updateOrCreate(
                    ['query' => mb_substr((string) $query, 0, 500)],
                    [
                        'campaign_id' => $rows->first()->campaign_id,
                        'verdict' => $verdict['verdict'],
                        'phrase' => $verdict['phrase'],
                        'reason' => $verdict['reason'],
                        'model' => $model,
                        'impressions' => (int) $rows->sum('impressions'),
                        'clicks' => (int) $rows->sum('clicks'),
                    ],
                );
                $judged++;
                if ($verdict['verdict'] === DirectQueryReview::FOREIGN) {
                    $foreign++;
                }
            }
        }

        return ['judged' => $judged, 'foreign' => $foreign, 'error' => null];
    }

    /**
     * Спросить модель. null — ответа нет, разбор откладываем.
     *
     * @param  array<int, string>  $queries
     * @return array<string, array{verdict: string, phrase: ?string, reason: ?string}>|null
     */
    private function ask(array $queries, string $model): ?array
    {
        try {
            $res = $this->openai->chat(
                [
                    ['role' => 'system', 'content' => JudgeSearchQueriesPrompt::systemMessage()],
                    ['role' => 'user', 'content' => JudgeSearchQueriesPrompt::userMessage($queries)],
                ],
                $model,
                ['response_format' => ['type' => 'json_object'], 'temperature' => 0],
            );
        } catch (\Throwable $e) {
            Log::warning('Direct: разбор запросов не удался', ['error' => $e->getMessage()]);

            return null;
        }

        $parsed = json_decode((string) ($res['content'] ?? ''), true);
        if (! is_array($parsed) || ! is_array($parsed['items'] ?? null)) {
            Log::warning('Direct: модель ответила не JSON-списком', ['answer' => mb_substr((string) ($res['content'] ?? ''), 0, 300)]);

            return null;
        }

        $out = [];
        foreach ($parsed['items'] as $item) {
            if (! is_array($item) || ! is_string($item['query'] ?? null)) {
                continue;
            }
            $verdict = (string) ($item['verdict'] ?? DirectQueryReview::UNCLEAR);
            $phrase = self::cleanPhrase($item['phrase'] ?? null);

            $out[mb_strtolower($item['query'])] = [
                // Чужой вердикт без пригодной минус-фразы бесполезен как
                // решение: исключать нечем, значит это «не уверена».
                'verdict' => $verdict === DirectQueryReview::FOREIGN && $phrase === null
                    ? DirectQueryReview::UNCLEAR
                    : (in_array($verdict, [DirectQueryReview::OURS, DirectQueryReview::FOREIGN], true) ? $verdict : DirectQueryReview::UNCLEAR),
                'phrase' => $phrase,
                'reason' => is_string($item['reason'] ?? null) ? mb_substr($item['reason'], 0, 500) : null,
            ];
        }

        return $out;
    }

    /**
     * Минус-фраза: чистим до того, что Директ примет, и отсекаем опасное.
     */
    public static function cleanPhrase(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $phrase = mb_strtolower(trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $raw) ?? '') ?? ''));
        if ($phrase === '' || mb_strlen($phrase) < 3) {
            return null;
        }
        if (count(preg_split('/\s+/u', $phrase) ?: []) > 3) {
            return null;
        }
        // Слова нашего же мира минус-словом быть не могут: такой минус выключит
        // живые запросы, и узнать об этом будет неоткуда — показы просто
        // перестанут приходить.
        foreach (self::PROTECTED_WORDS as $word) {
            if (preg_match('/(^|\s)'.preg_quote($word, '/').'/u', $phrase)) {
                return null;
            }
        }

        return $phrase;
    }

    /** Слова, которые встречаются в наших же запросах. */
    private const PROTECTED_WORDS = [
        'лифт', 'эскалатор', 'траволатор', 'подъёмник', 'подъемник',
        'поручень', 'поручни', 'ступен', 'гребен', 'гребён', 'балюстрад',
        'цепь', 'цепи', 'ремень', 'ремни', 'направляющ', 'башмак', 'вкладыш',
        'привод', 'лебед', 'лебёд', 'редуктор', 'станц', 'плата', 'платы',
        'энкодер', 'датчик', 'фотозавес', 'кнопка', 'кнопки', 'канат',
        'ролик', 'шкив', 'микровыключател', 'двер', 'кабин', 'шахт',
        'otis', 'kone', 'schindler', 'thyssen', 'sigma', 'fermator', 'wittur',
    ];

    /**
     * Применить минус-фразу к кампании запроса.
     *
     * @return array{ok: bool, message: string}
     */
    public function exclude(DirectQueryReview $review, ?User $by = null): array
    {
        $phrase = $review->phrase;
        if ($phrase === null) {
            return ['ok' => false, 'message' => 'У запроса нет минус-фразы — исключать нечем.'];
        }
        $campaignId = (int) ($review->campaign_id ?: $this->publisher->campaignId());
        if ($campaignId <= 0) {
            return ['ok' => false, 'message' => 'Неизвестно, в какой кампании показывался запрос.'];
        }

        $current = $this->negatives($campaignId, $by);
        if ($current === null) {
            return ['ok' => false, 'message' => 'Не удалось прочитать минус-фразы кампании.'];
        }
        if (in_array($phrase, $current, true)) {
            $this->decide($review, DirectQueryReview::EXCLUDED, $by);

            return ['ok' => true, 'message' => "«{$phrase}» уже была в минус-фразах кампании."];
        }
        if (count($current) >= self::CAMPAIGN_LIMIT) {
            return ['ok' => false, 'message' => 'В кампании уже '.self::CAMPAIGN_LIMIT.' минус-фраз — предел Директа.'];
        }

        $res = $this->publisher->call('campaigns', 'update', [
            'Campaigns' => [[
                'Id' => $campaignId,
                'NegativeKeywords' => ['Items' => array_values(array_merge($current, [$phrase]))],
            ]],
        ], null, $by);

        if (! $res['ok']) {
            return ['ok' => false, 'message' => DirectPublisherService::errorText($res)];
        }
        $errors = DirectPublisherService::resultErrors($res['result'] ?? null);
        if ($errors !== '') {
            return ['ok' => false, 'message' => $errors];
        }

        $this->decide($review, DirectQueryReview::EXCLUDED, $by);

        return ['ok' => true, 'message' => "Минус-фраза «{$phrase}» добавлена в кампанию #{$campaignId}."];
    }

    /** Оставить как есть: запрос наш или человек так решил. */
    public function keep(DirectQueryReview $review, ?User $by = null): void
    {
        $this->decide($review, DirectQueryReview::KEPT, $by);
    }

    private function decide(DirectQueryReview $review, string $decision, ?User $by): void
    {
        $review->forceFill([
            'decision' => $decision,
            'decided_at' => now(),
            'decided_by_user_id' => $by?->id,
        ])->save();
    }

    /**
     * Минус-фразы кампании. null — прочитать не удалось.
     *
     * @return array<int, string>|null
     */
    public function negatives(int $campaignId, ?User $by = null): ?array
    {
        $res = $this->publisher->call('campaigns', 'get', [
            'SelectionCriteria' => ['Ids' => [$campaignId]],
            'FieldNames' => ['Id', 'NegativeKeywords'],
        ], null, $by);

        if (! $res['ok']) {
            return null;
        }

        $campaign = ($res['result']['Campaigns'] ?? [])[0] ?? null;

        return array_values(array_filter(array_map(
            fn ($p) => mb_strtolower(trim((string) $p)),
            $campaign['NegativeKeywords']['Items'] ?? [],
        )));
    }

    /**
     * Автоматический режим: применить уверенные «чужие» вердикты.
     *
     * @return array{applied: int, messages: array<int, string>}
     */
    public function applyAuto(?User $by = null, bool $force = false): array
    {
        if (! $force && ! $this->autoEnabled()) {
            return ['applied' => 0, 'messages' => []];
        }

        $applied = 0;
        $messages = [];
        $pending = DirectQueryReview::query()
            ->whereNull('decision')
            ->where('verdict', DirectQueryReview::FOREIGN)
            ->whereNotNull('phrase')
            ->orderByDesc('impressions')
            ->limit(20)
            ->get();

        foreach ($pending as $review) {
            $res = $this->exclude($review, $by);
            if ($res['ok']) {
                $applied++;
            }
            $messages[] = $res['message'];
        }

        return ['applied' => $applied, 'messages' => array_slice($messages, 0, 5)];
    }
}
