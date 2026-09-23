<?php

namespace App\Services\Direct;

use App\Models\DirectPublishedAd;
use App\Models\DirectStat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Что в Директе приносит показы.
 *
 * Живой счётчик кампании (`campaigns.get` → Statistics) обновляется почти
 * сразу, но не говорит, ОТКУДА показы. Разрез даёт только сервис отчётов, и он
 * отстаёт на несколько часов: первые запросы возвращают одни заголовки без
 * строк. Поэтому тянем раз в час и перезаписываем дни целиком — отчёт со
 * временем догоняет сам себя.
 *
 * Отчёт готовится офлайн: на 201/202 надо повторить запрос С ТЕМ ЖЕ именем,
 * пока не придёт 200. Новое имя каждый раз — это новый отчёт и бесконечное
 * ожидание.
 */
class DirectStatsService
{
    /** За сколько дней тянем: отчёт уточняется задним числом. */
    public const WINDOW_DAYS = 14;

    /**
     * Забрать оба разреза. Возвращает, сколько строк записано.
     *
     * @return array{criteria: int, queries: int, error: ?string}
     */
    public function pull(): array
    {
        $skuByGroup = DirectPublishedAd::query()
            ->whereNotNull('ad_group_id')
            ->pluck('sku', 'ad_group_id')
            ->all();

        // Без фильтра по кампании: токен выдан на аккаунт, а мусорный запрос
        // приходит туда, куда его принесло — смотреть надо всё, что крутится.
        $criteria = $this->report('mz-criteria', 'CRITERIA_PERFORMANCE_REPORT', [
            'Date', 'CampaignId', 'AdGroupId', 'Criteria', 'CriteriaType', 'Impressions', 'Clicks', 'Cost',
        ]);
        $queries = $this->report('mz-queries', 'SEARCH_QUERY_PERFORMANCE_REPORT', [
            'Date', 'CampaignId', 'AdGroupId', 'Query', 'Criteria', 'CriteriaType', 'Impressions', 'Clicks', 'Cost',
        ]);
        // Итоги по кампаниям приходят отдельным отчётом: разрез по условиям
        // показа отстаёт сильнее прочих, а видеть расход надо каждый день.
        $byCampaign = $this->report('mz-campaigns', 'CAMPAIGN_PERFORMANCE_REPORT', [
            'Date', 'CampaignId', 'CampaignName', 'Impressions', 'Clicks', 'Cost',
        ]);
        if ($byCampaign !== null) {
            $this->store(array_map(fn ($r) => $r + ['Criteria' => $r['CampaignName'] ?? ''], $byCampaign), DirectStat::KIND_CAMPAIGN, []);
        }

        if ($criteria === null && $queries === null) {
            return ['criteria' => 0, 'queries' => 0, 'error' => 'Отчёты ещё готовятся — попробуем в следующий раз.'];
        }

        return [
            'criteria' => $this->store($criteria ?? [], DirectStat::KIND_CRITERIA, $skuByGroup),
            'queries' => $this->store($queries ?? [], DirectStat::KIND_QUERY, $skuByGroup),
            'error' => null,
        ];
    }

    /**
     * Сводка для раздела: что принесло показы за последние дни.
     *
     * @return array{days: int, impressions: int, clicks: int, cost: float, auto: array{impressions: int, clicks: int}, phrases: Collection<int, DirectStat>, queries: Collection<int, DirectStat>}
     */
    public function summary(int $days = 7): array
    {
        $rows = DirectStat::query()
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->get();

        $criteria = $rows->where('kind', DirectStat::KIND_CRITERIA);
        $queries = $rows->where('kind', DirectStat::KIND_QUERY);
        // Итог берём из отчёта по кампаниям, а где его ещё нет — из запросов:
        // разрезы приезжают в разное время, а ноль на экране читается как
        // «показов нет», хотя они есть.
        $totals = $rows->where('kind', DirectStat::KIND_CAMPAIGN);
        $base = $totals->isNotEmpty() ? $totals : ($criteria->isNotEmpty() ? $criteria : $queries);
        $auto = $queries->filter(fn (DirectStat $r) => $r->isAutotargeting());

        return [
            'days' => $days,
            'impressions' => (int) $base->sum('impressions'),
            'clicks' => (int) $base->sum('clicks'),
            'cost' => (float) $base->sum('cost'),
            'auto' => [
                'impressions' => (int) $auto->sum('impressions'),
                'clicks' => (int) $auto->sum('clicks'),
            ],
            // По кампаниям аккаунта, а не только по нашей.
            'campaigns' => $totals->groupBy('campaign_id')
                ->map(fn ($g) => [
                    'id' => (int) $g->first()->campaign_id,
                    'name' => (string) $g->first()->name,
                    'impressions' => (int) $g->sum('impressions'),
                    'clicks' => (int) $g->sum('clicks'),
                    'cost' => (float) $g->sum('cost'),
                    'ours' => (int) $g->first()->campaign_id === (int) app(DirectPublisherService::class)->campaignId(),
                ])
                ->sortByDesc('impressions')->values(),
            // Наши фразы — то, что мы придумали сами; по ним видно, оправдались
            // ли догадки про артикулы.
            'phrases' => $criteria->reject(fn (DirectStat $r) => $r->isAutotargeting())
                ->groupBy('name')
                ->map(fn ($g) => $this->fold($g))
                ->sortByDesc('impressions')->take(10)->values(),
            // Запросы людей — материал и для новых фраз, и для минус-слов.
            'queries' => $queries
                ->groupBy('name')
                ->map(fn ($g) => $this->fold($g))
                ->sortByDesc('impressions')->take(30)->values(),
        ];
    }

    /**
     * @param  Collection<int, DirectStat>  $group
     * @return array<string, mixed>
     */
    private function fold(Collection $group): array
    {
        return [
            'name' => (string) $group->first()->name,
            'auto' => $group->first()->isAutotargeting(),
            'campaign_id' => (int) ($group->first()->campaign_id ?? 0),
            'sku' => $group->pluck('sku')->filter()->unique()->take(3)->implode(', '),
            'impressions' => (int) $group->sum('impressions'),
            'clicks' => (int) $group->sum('clicks'),
            'cost' => (float) $group->sum('cost'),
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @param  array<int, string>  $skuByGroup
     */
    private function store(array $rows, string $kind, array $skuByGroup): int
    {
        $saved = 0;
        foreach ($rows as $row) {
            $name = trim((string) ($row['Query'] ?? $row['Criteria'] ?? ''));
            if ($name === '') {
                continue;
            }
            $groupId = (int) ($row['AdGroupId'] ?? 0);

            DirectStat::query()->updateOrCreate(
                [
                    'date' => $row['Date'] ?? now()->toDateString(),
                    'kind' => $kind,
                    'name' => mb_substr($name, 0, 500),
                    'matched' => $kind === DirectStat::KIND_QUERY ? mb_substr((string) ($row['Criteria'] ?? ''), 0, 500) : null,
                    'criteria_type' => (string) ($row['CriteriaType'] ?? ''),
                ],
                [
                    'campaign_id' => (int) ($row['CampaignId'] ?? 0) ?: null,
                    'sku' => $skuByGroup[$groupId] ?? null,
                    'impressions' => (int) ($row['Impressions'] ?? 0),
                    'clicks' => (int) ($row['Clicks'] ?? 0),
                    'cost' => (float) str_replace(',', '.', (string) ($row['Cost'] ?? 0)),
                ],
            );
            $saved++;
        }

        return $saved;
    }

    /**
     * Один отчёт. null — ещё готовится либо не отдался.
     *
     * @param  array<int, string>  $fields
     * @return array<int, array<string, string>>|null
     */
    private function report(string $name, string $type, array $fields): ?array
    {
        $definition = [
            'params' => [
                'SelectionCriteria' => (object) [],
                'FieldNames' => $fields,
                // Имя постоянное: по нему Директ отдаёт уже заказанный отчёт.
                // В имени — отпечаток состава полей: Директ хранит отчёт по
                // имени и на изменившийся набор колонок молча отдаёт старый.
                // Так мы сутки получали строки без номера кампании.
                'ReportName' => $name.'-'.self::WINDOW_DAYS.'d-'.substr(md5($type.implode(',', $fields)), 0, 6),
                'ReportType' => $type,
                'DateRangeType' => 'LAST_'.self::WINDOW_DAYS.'_DAYS',
                'Format' => 'TSV',
                'IncludeVAT' => 'YES',
            ],
        ];

        try {
            $res = Http::withToken((string) config('services.yandex_direct.token'))
                ->withHeaders([
                    'Accept-Language' => 'ru',
                    'processingMode' => 'auto',
                    'returnMoneyInMicros' => 'false',
                    'skipReportHeader' => 'true',
                    'skipReportSummary' => 'true',
                ])
                ->timeout(180)
                ->post((string) config('services.yandex_direct.endpoint').'reports', $definition);
        } catch (\Throwable $e) {
            Log::warning('Direct: отчёт недоступен', ['report' => $name, 'error' => $e->getMessage()]);

            return null;
        }

        if ($res->status() !== 200) {
            if ($res->status() >= 400) {
                Log::warning('Direct: отчёт с ошибкой', ['report' => $name, 'status' => $res->status(), 'body' => mb_substr($res->body(), 0, 300)]);
            }

            return null;
        }

        return self::parseTsv($res->body());
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function parseTsv(string $body): array
    {
        $lines = preg_split('/\r?\n/', trim($body)) ?: [];
        $header = str_getcsv((string) array_shift($lines), "\t", '"', '\\');
        if ($header === [null] || $header === []) {
            return [];
        }

        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, "\t", '"', '\\');
            if (count($cells) !== count($header)) {
                continue;
            }
            $out[] = array_combine($header, $cells);
        }

        return $out;
    }
}
