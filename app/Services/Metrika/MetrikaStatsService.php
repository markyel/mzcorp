<?php

namespace App\Services\Metrika;

use App\Models\MetrikaDailyStat;
use App\Models\MetrikaGoal;
use App\Models\MetrikaPhraseStat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Визиты и цели из Яндекс Метрики — то, чего не знает Директ.
 *
 * Директ говорит, сколько стоил клик; Метрика — что человек сделал на сайте.
 * В общем счётчике видны и наши кампании, и агентские, поэтому по одним и тем
 * же целям их можно сравнивать честно (Директ по умолчанию считает конверсии
 * по всем целям подряд, включая «Переход из спецразмещения»).
 *
 * Атрибуция — последний значимый переход (lastsign), как в отчётах Директа.
 * Отчёт уточняется задним числом, поэтому окно переписывается целиком.
 */
class MetrikaStatsService
{
    /** Метрика принимает не больше 20 метрик в запросе. */
    private const MAX_METRICS = 20;

    private const BASE_METRICS = ['ym:s:visits', 'ym:s:bounceRate', 'ym:s:pageDepth', 'ym:s:avgVisitDurationSeconds'];

    private const DIMENSIONS = [
        MetrikaDailyStat::KIND_DIRECT_CAMPAIGN => 'ym:s:lastsignDirectClickOrder',
        MetrikaDailyStat::KIND_SOURCE => 'ym:s:lastsignTrafficSource',
    ];

    /**
     * Обновить справочник целей и статистику за окно.
     *
     * @return array{goals: int, rows: int, errors: list<string>}
     */
    public function pull(?int $days = null): array
    {
        $days = max(1, $days ?? (int) config('services.yandex_metrika.window_days', 14));
        $from = now()->subDays($days - 1)->toDateString();
        $to = now()->toDateString();
        $goals = 0;
        $rows = 0;
        $errors = [];

        foreach ((array) config('services.yandex_metrika.counters', []) as $counterId) {
            $counterId = (int) $counterId;
            $ids = $this->syncGoals($counterId, $errors);
            $goals += count($ids);

            foreach (self::DIMENSIONS as $kind => $dimension) {
                $data = $this->collect($counterId, $dimension, $ids, $from, $to, $errors);
                if ($data === null) {
                    continue;
                }
                foreach ($data as $row) {
                    MetrikaDailyStat::query()->updateOrCreate(
                        ['date' => $row['date'], 'counter_id' => $counterId, 'kind' => $kind, 'key' => $row['key']],
                        [
                            'name' => mb_substr($row['name'], 0, 500),
                            'visits' => $row['visits'],
                            'bounce_rate' => $row['bounce_rate'],
                            'page_depth' => $row['page_depth'],
                            'avg_visit_seconds' => $row['avg_visit_seconds'],
                            'goals' => $row['goals'] ?: null,
                        ],
                    );
                    $rows++;
                }
            }

            $rows += $this->pullPhrases($counterId, $from, $to, $errors);
        }

        return ['goals' => $goals, 'rows' => $rows, 'errors' => $errors];
    }

    /**
     * Визиты и цели по фразе Директа и поисковому запросу — чтобы видеть, какая
     * фраза дала обращение, а не только какая кампания. Цели — только из
     * goal_groups (в один запрос), окно переписывается целиком: Метрика
     * уточняет отчёт задним числом, и строка может исчезнуть.
     *
     * @param  list<string>  $errors
     */
    private function pullPhrases(int $counterId, string $from, string $to, array &$errors): int
    {
        $goalIds = collect((array) config('services.yandex_metrika.goal_groups', []))
            ->flatMap(fn ($g) => (array) ($g['ids'] ?? []))
            ->map(fn ($id) => (int) $id)->unique()->values()
            ->take(self::MAX_METRICS - 1)->all();
        $metrics = array_merge(['ym:s:visits'], array_map(fn (int $id) => "ym:s:goal{$id}reaches", $goalIds));

        $out = [];
        $offset = 1;
        do {
            $res = $this->get('stat/v1/data', [
                'ids' => $counterId,
                'dimensions' => 'ym:s:date,ym:s:lastsignDirectClickOrder,ym:s:lastsignDirectPhraseOrCond,ym:s:lastsignDirectSearchPhrase',
                'metrics' => implode(',', $metrics),
                'date1' => $from,
                'date2' => $to,
                'accuracy' => 'full',
                'limit' => 10000,
                'offset' => $offset,
                'lang' => 'ru',
            ]);
            if ($res === null) {
                $errors[] = "счётчик {$counterId}: отчёт по фразам Директа не получен";

                return 0;
            }
            foreach ($res['data'] ?? [] as $row) {
                $date = (string) ($row['dimensions'][0]['name'] ?? '');
                $campaignId = (int) ($row['dimensions'][1]['id'] ?? 0);
                if ($date === '' || $campaignId === 0) {
                    continue;
                }
                $condition = mb_substr((string) ($row['dimensions'][2]['name'] ?? ''), 0, 1000);
                $query = $row['dimensions'][3]['name'] ?? null;
                $query = $query !== null ? mb_substr((string) $query, 0, 1000) : null;
                $hash = md5($condition.'|'.($query ?? ''));
                $key = $date.'|'.$campaignId.'|'.$hash;

                $out[$key] ??= [
                    'date' => $date, 'counter_id' => $counterId, 'campaign_id' => $campaignId,
                    'condition' => $condition, 'search_query' => $query, 'row_hash' => $hash,
                    'visits' => 0, 'goals' => [],
                ];
                $out[$key]['visits'] += (int) round((float) ($row['metrics'][0] ?? 0));
                foreach ($goalIds as $i => $id) {
                    $n = (int) round((float) ($row['metrics'][$i + 1] ?? 0));
                    if ($n > 0) {
                        $out[$key]['goals'][(string) $id] = ($out[$key]['goals'][(string) $id] ?? 0) + $n;
                    }
                }
            }
            $total = (int) ($res['total_rows'] ?? 0);
            $offset += 10000;
        } while ($offset <= $total);

        $now = now();
        $insert = array_map(fn (array $r) => array_merge($r, [
            'goals' => $r['goals'] !== [] ? json_encode($r['goals']) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]), array_values($out));

        \Illuminate\Support\Facades\DB::transaction(function () use ($counterId, $from, $to, $insert) {
            MetrikaPhraseStat::query()->where('counter_id', $counterId)->whereBetween('date', [$from, $to])->delete();
            foreach (array_chunk($insert, 500) as $chunk) {
                MetrikaPhraseStat::query()->insert($chunk);
            }
        });

        return count($insert);
    }

    /**
     * Фразы и запросы наших кампаний за последние $days дней.
     *
     * $withLeads — только строки с обращениями или кликами по контактам
     * (фраза + запрос); иначе — все фразы без разбивки по запросам, по
     * убыванию визитов: где трафик есть, а толку нет.
     *
     * @param  list<int>  $campaignIds
     * @return Collection<int, array{campaign_id: int, condition: string, query: ?string, visits: int, groups: array<string, int>, leads: int}>
     */
    public function phrases(int $days, array $campaignIds, bool $withLeads, int $limit = 50): Collection
    {
        $groups = (array) config('services.yandex_metrika.goal_groups', []);
        // «Из спецразмещения» засчитывается за сам клик — это не результат.
        $resultCodes = array_values(array_diff(array_keys($groups), ['premium']));

        return MetrikaPhraseStat::query()
            ->whereIn('campaign_id', $campaignIds)
            ->where('date', '>=', Carbon::today()->subDays($days - 1)->toDateString())
            ->get()
            ->groupBy(fn (MetrikaPhraseStat $r) => $r->campaign_id.'|'.$r->condition.($withLeads ? '|'.$r->search_query : ''))
            ->map(function (Collection $g) use ($groups, $resultCodes, $withLeads) {
                $byGroup = [];
                foreach ($groups as $code => $def) {
                    $byGroup[$code] = (int) $g->sum(fn (MetrikaPhraseStat $r) => array_sum(array_map(fn ($id) => $r->reaches((int) $id), (array) ($def['ids'] ?? []))));
                }
                $first = $g->first();

                return [
                    'campaign_id' => (int) $first->campaign_id,
                    'condition' => (string) $first->condition,
                    'query' => $withLeads ? $first->search_query : null,
                    'visits' => (int) $g->sum('visits'),
                    'groups' => $byGroup,
                    'leads' => array_sum(array_map(fn ($c) => $byGroup[$c] ?? 0, $resultCodes)),
                ];
            })
            ->when($withLeads, fn (Collection $c) => $c->filter(fn ($r) => $r['leads'] > 0))
            ->sortBy($withLeads ? [['leads', 'desc'], ['visits', 'desc']] : [['visits', 'desc']])
            ->take($limit)
            ->values();
    }

    /**
     * Справочник целей счётчика. Возвращает номера целей.
     *
     * @param  list<string>  $errors
     * @return list<int>
     */
    private function syncGoals(int $counterId, array &$errors): array
    {
        $res = $this->get("management/v1/counter/{$counterId}/goals");
        if ($res === null) {
            $errors[] = "счётчик {$counterId}: цели не получены";

            return MetrikaGoal::query()->where('counter_id', $counterId)->pluck('goal_id')->map(fn ($v) => (int) $v)->all();
        }

        $ids = [];
        foreach ($res['goals'] ?? [] as $goal) {
            $id = (int) ($goal['id'] ?? 0);
            if ($id === 0) {
                continue;
            }
            MetrikaGoal::query()->updateOrCreate(
                ['counter_id' => $counterId, 'goal_id' => $id],
                ['name' => mb_substr((string) ($goal['name'] ?? $id), 0, 500), 'type' => $goal['type'] ?? null],
            );
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Строки отчёта по дням: базовые метрики одним запросом, цели — пачками
     * (лимит 20 метрик на запрос).
     *
     * @param  list<int>  $goalIds
     * @param  list<string>  $errors
     * @return list<array{date: string, key: string, name: string, visits: int, bounce_rate: ?float, page_depth: ?float, avg_visit_seconds: ?int, goals: array<string, int>}>|null
     */
    private function collect(int $counterId, string $dimension, array $goalIds, string $from, string $to, array &$errors): ?array
    {
        $base = $this->table($counterId, $dimension, self::BASE_METRICS, $from, $to);
        if ($base === null) {
            $errors[] = "счётчик {$counterId}: отчёт {$dimension} не получен";

            return null;
        }

        $out = [];
        foreach ($base as $k => $row) {
            [$v, $bounce, $depth, $duration] = $row['metrics'];
            $out[$k] = [
                'date' => $row['date'],
                'key' => $row['key'],
                'name' => $row['name'],
                'visits' => (int) round($v),
                'bounce_rate' => $bounce !== null ? round((float) $bounce, 2) : null,
                'page_depth' => $depth !== null ? round((float) $depth, 2) : null,
                'avg_visit_seconds' => $duration !== null ? (int) round((float) $duration) : null,
                'goals' => [],
            ];
        }

        foreach (array_chunk($goalIds, self::MAX_METRICS) as $chunk) {
            $metrics = array_map(fn (int $id) => "ym:s:goal{$id}reaches", $chunk);
            $part = $this->table($counterId, $dimension, $metrics, $from, $to);
            if ($part === null) {
                $errors[] = "счётчик {$counterId}: цели в разрезе {$dimension} не получены";

                continue;
            }
            foreach ($part as $k => $row) {
                if (! isset($out[$k])) {
                    continue;
                }
                foreach ($chunk as $i => $id) {
                    $n = (int) round((float) ($row['metrics'][$i] ?? 0));
                    if ($n > 0) {
                        $out[$k]['goals'][(string) $id] = $n;
                    }
                }
            }
        }

        return array_values($out);
    }

    /**
     * Один отчёт stat/v1/data по дням и разрезу.
     *
     * @param  list<string>  $metrics
     * @return array<string, array{date: string, key: string, name: string, metrics: array<int, float|null>}>|null
     */
    private function table(int $counterId, string $dimension, array $metrics, string $from, string $to): ?array
    {
        $out = [];
        $offset = 1;
        do {
            $res = $this->get('stat/v1/data', [
                'ids' => $counterId,
                'dimensions' => 'ym:s:date,'.$dimension,
                'metrics' => implode(',', $metrics),
                'date1' => $from,
                'date2' => $to,
                'accuracy' => 'full',
                'limit' => 10000,
                'offset' => $offset,
                'lang' => 'ru',
            ]);
            if ($res === null) {
                return null;
            }
            foreach ($res['data'] ?? [] as $row) {
                $date = (string) ($row['dimensions'][0]['name'] ?? '');
                $dim = $row['dimensions'][1] ?? [];
                // Номер кампании Директа или код источника; без него строку
                // не с чем связать — это «не определено».
                $key = (string) ($dim['id'] ?? '');
                if ($date === '' || $key === '') {
                    continue;
                }
                $out[$date.'|'.$key] = [
                    'date' => $date,
                    'key' => $key,
                    'name' => (string) ($dim['name'] ?? $key),
                    'metrics' => $row['metrics'] ?? [],
                ];
            }
            $total = (int) ($res['total_rows'] ?? 0);
            $offset += 10000;
        } while ($offset <= $total);

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function get(string $path, array $query = []): ?array
    {
        try {
            $res = Http::withToken((string) config('services.yandex_metrika.token'))
                ->timeout(60)
                ->get(rtrim((string) config('services.yandex_metrika.endpoint'), '/').'/'.$path, $query);
        } catch (\Throwable $e) {
            Log::warning('Metrika: запрос не прошёл', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $res->successful()) {
            Log::warning('Metrika: ошибка ответа', ['path' => $path, 'status' => $res->status(), 'body' => mb_substr($res->body(), 0, 300)]);

            return null;
        }

        return $res->json();
    }

    /**
     * Сводка по кампаниям Директа за последние $days дней — для раздела.
     * Кампания «наша», если её номер есть в статистике нашего аккаунта Директа.
     *
     * @param  list<int>  $ourCampaignIds
     * @return Collection<int, array{id: string, name: string, ours: bool, visits: int, bounce_rate: ?float, page_depth: ?float, groups: array<string, int>}>
     */
    public function campaigns(int $days, array $ourCampaignIds): Collection
    {
        $groups = (array) config('services.yandex_metrika.goal_groups', []);

        return MetrikaDailyStat::query()
            ->where('kind', MetrikaDailyStat::KIND_DIRECT_CAMPAIGN)
            ->where('date', '>=', Carbon::today()->subDays($days - 1)->toDateString())
            ->get()
            ->groupBy('key')
            ->map(function (Collection $g) use ($groups, $ourCampaignIds) {
                $visits = (int) $g->sum('visits');
                // Отказы и глубина — взвешенные по визитам, а не среднее дней.
                $weighted = fn (string $f) => $visits > 0
                    ? round($g->sum(fn (MetrikaDailyStat $r) => (float) $r->{$f} * $r->visits) / $visits, 1)
                    : null;
                $byGroup = [];
                foreach ($groups as $code => $def) {
                    $byGroup[$code] = (int) $g->sum(fn (MetrikaDailyStat $r) => array_sum(array_map(fn ($id) => $r->reaches((int) $id), (array) ($def['ids'] ?? []))));
                }

                return [
                    'id' => (string) $g->first()->key,
                    'name' => (string) $g->sortByDesc('date')->first()->name,
                    'ours' => in_array((int) $g->first()->key, $ourCampaignIds, true),
                    'visits' => $visits,
                    'bounce_rate' => $weighted('bounce_rate'),
                    'page_depth' => $weighted('page_depth'),
                    'groups' => $byGroup,
                ];
            })
            ->sortByDesc('visits')
            ->values();
    }
}
