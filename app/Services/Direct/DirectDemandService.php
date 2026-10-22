<?php

namespace App\Services\Direct;

use App\Models\DirectPhraseDemand;
use Illuminate\Support\Facades\Log;

/**
 * Спрос на фразу: ищут ли её вообще.
 *
 * Зачем вообще: 22.09.2026 замер показал, что 274 наши фразы из 382 имеют ноль
 * показов в месяц. По таким запросам Яндекс не проводит аукцион — в выдаче нет
 * рекламы ни нашей, ни чужой, сколько ни ставь. Значит фразу надо проверять
 * ДО создания, а не выяснять по нулевой статистике неделю спустя.
 *
 * Как меряем. До 08.10.2026 частоту отдавал старый Live v4 (`CreateNewForecast` /
 * `GetForecast`); с 08.10 Яндекс его отключил — «Метод недоступен в данной версии
 * API» (509), и новые фразы две недели не мерились вовсе. Теперь — API v5
 * `keywordsresearch.hasSearchVolume`: синхронно, дёшево, но отвечает только «есть
 * спрос / нет». План берёт фразу при спросе больше нуля, поэтому этого хватает:
 * есть спрос — храним прежнюю измеренную частоту (если была) или 1; нет — 0.
 * Ответы храним: спрос месяцами не меняется.
 */
class DirectDemandService
{
    /** Фраз в одном запросе hasSearchVolume. */
    public const CHUNK = 100;

    /** Сколько фраз меряем за один прогон конвейера. */
    public const MAX_PER_RUN = 300;

    /** Как долго верим измеренному значению. */
    public const FRESH_DAYS = 30;

    public function __construct(private readonly DirectApiClient $api) {}

    /**
     * Частота известных фраз: фраза → показов в месяц (1 — спрос есть, частота не измерена).
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
     * Померить фразы (есть ли спрос). Возвращает, сколько фраз измерено.
     *
     * @param  array<int, string>  $phrases
     */
    public function measure(array $phrases): int
    {
        $phrases = array_slice(array_values(array_unique(array_filter($phrases))), 0, self::MAX_PER_RUN);
        $measured = 0;

        foreach (array_chunk($phrases, self::CHUNK) as $chunk) {
            $res = $this->api->call('keywordsresearch', 'hasSearchVolume', [
                'SelectionCriteria' => ['Keywords' => array_values($chunk), 'RegionIds' => DirectPublisherService::regionIds()],
                'FieldNames' => ['Keyword', 'AllDevices'],
            ]);
            if (! $res['ok']) {
                Log::warning('Direct: спрос не измерен', ['error' => $res['error']]);

                continue;
            }
            foreach ($res['result']['HasSearchVolumeResults'] ?? [] as $row) {
                $phrase = (string) ($row['Keyword'] ?? '');
                if ($phrase === '') {
                    continue;
                }
                $was = (int) DirectPhraseDemand::query()->where('phrase', $phrase)->value('shows');
                DirectPhraseDemand::query()->updateOrCreate(
                    ['phrase' => $phrase],
                    // Спрос есть — прежняя измеренная частота или 1; нет — 0.
                    ['shows' => ($row['AllDevices'] ?? 'NO') === 'YES' ? max(1, $was) : 0, 'checked_at' => now()],
                );
                $measured++;
            }
        }

        return $measured;
    }
}
