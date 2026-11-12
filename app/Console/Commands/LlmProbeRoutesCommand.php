<?php

namespace App\Console\Commands;

use App\Services\AI\OpenAIProxyPool;
use App\Services\AI\OpenAIRoute;
use App\Services\AI\OpenAIRouteJournal;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Опрос маршрутов к OpenAI (ТЗ 12.11.2026): бесплатная проверка каждого — в журнал доступности
 * (OpenAIRouteJournal). По расписанию раз в 15 минут; --show — таблица очереди.
 *
 *   php artisan llm:probe-routes
 *   php artisan llm:probe-routes --show
 */
class LlmProbeRoutesCommand extends Command
{
    protected $signature = 'llm:probe-routes {--show : показать журнал без опроса}';

    protected $description = 'Маршруты к OpenAI: опрос доступности и журнал';

    public function handle(OpenAIProxyPool $pool, OpenAIRouteJournal $journal): int
    {
        $routes = $pool->routes();
        if (! $this->option('show')) {
            foreach ($routes as $route) {
                $pool->probe($route);
            }
            $journal->finishProbe(array_map(fn (OpenAIRoute $r) => $r->key, $routes));
        }

        $j = $journal->all();
        $queue = array_map(fn (OpenAIRoute $r) => $r->key, $pool->candidates());
        $tz = config('app.timezone');
        $rows = [];
        foreach ($routes as $route) {
            $e = $j['routes'][$route->key] ?? null;
            $pos = array_search($route->key, $queue, true);
            $rows[] = [
                $pos === false ? '—' : $pos + 1,
                $route->key,
                $e === null ? 'не проверен' : ($e['ok'] ? 'да' : 'нет'),
                $e === null ? '' : ($e['latency_ms'] ?? '—').' / '.($e['last_ms'] ?? '—'),
                $e === null ? '' : Carbon::parse($e['checked_at'])->timezone($tz)->format('d.m H:i:s'),
                $e['fails'] ?? '',
                $e['error'] ?? '',
            ];
        }
        $this->table(['Очередь', 'Маршрут', 'Доступен', 'мс (сглаж. / посл.)', 'Проверен', 'Сбоев', 'Ошибка'], $rows);
        $probed = $j['probed_at'] ? Carbon::parse($j['probed_at']) : null;
        $this->line('Опрос: '.($probed ? $probed->timezone($tz)->format('d.m H:i:s') : 'не было')
            .($probed && $probed->lt(now()->subMinutes(OpenAIRouteJournal::STALE_MINUTES)) ? ' — журнал устарел, очередь по конфигу' : ''));

        return self::SUCCESS;
    }
}
