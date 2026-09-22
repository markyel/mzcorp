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

    /** Ключ настройки со списком заказанных, но ещё не прочитанных отчётов. */
    public const SETTING_PENDING = 'direct.forecast_pending';

    /** «Отчет с прогнозом в процессе подготовки» — ждём следующего прогона. */
    public const ERROR_NOT_READY = 74;

    /** Сколько ждём заказанный отчёт, прежде чем заказать фразу заново. */
    public const ORDER_TTL_HOURS = 6;

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

        // Не переспрашиваем ни свежее, ни то, что уже заказано и ждёт отчёта:
        // иначе каждый час уходил бы новый заказ на те же фразы.
        $skip = DirectPhraseDemand::query()
            ->whereIn('phrase', $phrases)
            ->where(fn ($q) => $q
                ->where('checked_at', '>', now()->subDays(self::FRESH_DAYS))
                ->orWhere(fn ($w) => $w->whereNull('checked_at')->where('updated_at', '>', now()->subHours(self::ORDER_TTL_HOURS)))
            )
            ->pluck('phrase')
            ->all();

        return array_values(array_diff($phrases, $skip));
    }

    /**
     * Забрать готовые отчёты и заказать новые.
     *
     * Отчёт готовится не мгновенно — сразу после заказа Директ отвечает
     * «Отчет с прогнозом в процессе подготовки» (код 74). Поэтому заказ и
     * чтение разнесены по прогонам: читаем то, что заказали в прошлый час,
     * и заказываем следующую порцию. Ждать внутри прогона незачем, конвейер
     * всё равно ходит каждый час.
     *
     * Возвращает, сколько фраз измерено в этот раз.
     *
     * @param  array<int, string>  $phrases
     */
    public function measure(array $phrases): int
    {
        $measured = $this->drainPending();

        $phrases = array_slice(array_values(array_unique(array_filter($phrases))), 0, self::MAX_PER_RUN);
        if ($phrases === []) {
            return $measured;
        }

        $pending = $this->pending();
        foreach (array_chunk($phrases, self::CHUNK) as $chunk) {
            $id = $this->order($chunk);
            if ($id === null) {
                continue;
            }
            $pending[] = $id;
            // Метка «заказано»: пустая checked_at и свежая updated_at. Без неё
            // следующий прогон закажет те же фразы ещё раз.
            foreach ($chunk as $phrase) {
                DirectPhraseDemand::query()->firstOrCreate(['phrase' => $phrase], ['shows' => 0, 'clicks' => 0]);
            }
            DirectPhraseDemand::query()->whereIn('phrase', $chunk)->whereNull('checked_at')->touch();
        }
        $this->rememberPending($pending);

        return $measured;
    }

    /**
     * Прочитать заказанные ранее отчёты. Неготовые оставляем на следующий раз.
     */
    private function drainPending(): int
    {
        $left = [];
        $measured = 0;

        foreach ($this->pending() as $id) {
            $rows = $this->read($id, $ready);
            if (! $ready) {
                $left[] = $id;

                continue;
            }
            foreach ($rows as $phrase => $numbers) {
                DirectPhraseDemand::query()->updateOrCreate(
                    ['phrase' => $phrase],
                    ['shows' => $numbers['shows'], 'clicks' => $numbers['clicks'], 'checked_at' => now()],
                );
                $measured++;
            }
        }
        $this->rememberPending($left);

        return $measured;
    }

    /** @return array<int, int> */
    private function pending(): array
    {
        $raw = app(\App\Services\Settings\SettingsService::class)->get(self::SETTING_PENDING, []);

        return array_values(array_filter(array_map('intval', is_array($raw) ? $raw : [])));
    }

    /** @param  array<int, int>  $ids */
    private function rememberPending(array $ids): void
    {
        app(\App\Services\Settings\SettingsService::class)->set(
            self::SETTING_PENDING,
            array_values(array_unique($ids)),
            \App\Models\AppSetting::TYPE_JSON,
            null,
            'Заказанные отчёты прогноза Директа, которые ещё не прочитаны',
        );
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
     * Прочитать отчёт. $ready=false — он ещё готовится, вернёмся позже.
     *
     * @return array<string, array{shows: int, clicks: int}>
     */
    private function read(int $id, ?bool &$ready = null): array
    {
        $res = $this->call('GetForecast', $id);
        $ready = true;

        if (isset($res['error_str'])) {
            // 74 — «Отчет с прогнозом в процессе подготовки», это не ошибка.
            // 31 — отчёта уже нет, повторять бессмысленно.
            $ready = (int) ($res['error_code'] ?? 0) !== self::ERROR_NOT_READY;
            if ($ready) {
                Log::warning('Direct: прогноз не прочитан', ['id' => $id, 'answer' => $res]);
            }

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
