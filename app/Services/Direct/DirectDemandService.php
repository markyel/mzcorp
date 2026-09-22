<?php

namespace App\Services\Direct;

use App\Models\DirectPhraseDemand;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Спрос на фразу: сколько раз в месяц её ищут.
 *
 * Зачем вообще: 22.09.2026 замер показал, что 274 наши фразы из 382 имеют ноль
 * показов в месяц. По таким запросам Яндекс не проводит аукцион — в выдаче нет
 * рекламы ни нашей, ни чужой, сколько ни ставь. Значит фразу надо проверять
 * ДО создания, а не выяснять по нулевой статистике неделю спустя.
 *
 * Частоту отдаёт только старый Live v4 (в API v5 её нет): заказываем отчёт
 * `CreateNewForecast`, читаем `GetForecast`. Ограничения, на которые уже
 * наступили: не больше 100 фраз за запрос, тело обязано быть явным UTF-8,
 * кавычки внутри фразы (каталожное «контакт дверной "папа"») отбиваются как
 * неверный оператор. Ответы храним: отчёт готовится не мгновенно, а частота
 * месяцами не меняется.
 */
class DirectDemandService
{
    /** Больше Директ за раз не принимает. */
    public const CHUNK = 100;

    /** Сколько фраз меряем за один прогон конвейера. */
    public const MAX_PER_RUN = 300;

    /** Как долго верим измеренному значению. */
    public const FRESH_DAYS = 30;

    /**
     * Частота известных фраз: фраза → показов в месяц.
     *
     * @param  array<int, string>  $phrases
     * @return array<string, int>
     */
    public function known(array $phrases): array
    {
        if ($phrases === []) {
            return [];
        }

        return DirectPhraseDemand::query()
            ->whereIn('phrase', array_values(array_unique($phrases)))
            ->pluck('shows', 'phrase')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Какие из фраз мы ещё не мерили (или померили слишком давно).
     *
     * @param  array<int, string>  $phrases
     * @return array<int, string>
     */
    public function unknown(array $phrases): array
    {
        $phrases = array_values(array_unique(array_filter($phrases)));
        if ($phrases === []) {
            return [];
        }

        $fresh = DirectPhraseDemand::query()
            ->whereIn('phrase', $phrases)
            ->where('checked_at', '>', now()->subDays(self::FRESH_DAYS))
            ->pluck('phrase')
            ->all();

        return array_values(array_diff($phrases, $fresh));
    }

    /**
     * Померить и запомнить. Возвращает, сколько фраз удалось измерить.
     *
     * @param  array<int, string>  $phrases
     */
    public function measure(array $phrases): int
    {
        $phrases = array_slice(array_values(array_unique(array_filter($phrases))), 0, self::MAX_PER_RUN);
        if ($phrases === []) {
            return 0;
        }

        $measured = 0;
        foreach (array_chunk($phrases, self::CHUNK) as $chunk) {
            $id = $this->order($chunk);
            if ($id === null) {
                continue;
            }
            foreach ($this->read($id) as $phrase => $numbers) {
                DirectPhraseDemand::query()->updateOrCreate(
                    ['phrase' => $phrase],
                    ['shows' => $numbers['shows'], 'clicks' => $numbers['clicks'], 'checked_at' => now()],
                );
                $measured++;
            }
        }

        return $measured;
    }

    /** Заказать отчёт. Возвращает его идентификатор. */
    private function order(array $chunk): ?int
    {
        $res = $this->call('CreateNewForecast', [
            'Phrases' => array_values($chunk),
            'GeoID' => DirectPublisherService::regionIds(),
            'Currency' => 'RUB',
        ]);

        if (! isset($res['data'])) {
            Log::warning('Direct: прогноз не заказан', ['answer' => $res]);

            return null;
        }

        return (int) $res['data'];
    }

    /**
     * Прочитать готовый отчёт.
     *
     * @return array<string, array{shows: int, clicks: int}>
     */
    private function read(int $id): array
    {
        $res = $this->call('GetForecast', $id);
        if (isset($res['error_str'])) {
            Log::warning('Direct: прогноз не прочитан', ['id' => $id, 'answer' => $res]);

            return [];
        }

        $out = [];
        foreach ($res['data']['Phrases'] ?? [] as $row) {
            $phrase = (string) ($row['Phrase'] ?? '');
            if ($phrase === '') {
                continue;
            }
            $out[$phrase] = ['shows' => (int) ($row['Shows'] ?? 0), 'clicks' => (int) ($row['Clicks'] ?? 0)];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function call(string $method, mixed $param): array
    {
        $token = (string) config('services.yandex_direct.token');
        // Тело собираем сами: Директ отбивает запрос без явного UTF-8 в
        // Content-Type («Request encoding is not UTF8»).
        $body = json_encode([
            'method' => $method,
            'token' => $token,
            'locale' => 'ru',
            'param' => $param,
        ], JSON_UNESCAPED_UNICODE);

        try {
            return Http::withBody((string) $body, 'application/json; charset=utf-8')
                ->timeout(120)
                ->post((string) config('services.yandex_direct.forecast_url'))
                ->json() ?? [];
        } catch (\Throwable $e) {
            Log::warning('Direct: прогноз недоступен', ['method' => $method, 'error' => $e->getMessage()]);

            return [];
        }
    }
}
