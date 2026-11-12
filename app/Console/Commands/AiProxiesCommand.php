<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Прежнее имя проверки прокси к OpenAI. С 12.11.2026 маршруты ведёт журнал доступности —
 * команда показывает его (--probe — опрашивает): llm:probe-routes.
 *
 *   php artisan ai:proxies
 *   php artisan ai:proxies --probe
 */
class AiProxiesCommand extends Command
{
    protected $signature = 'ai:proxies {--probe : проверить каждый маршрут сейчас}';

    protected $description = 'Маршруты к OpenAI: журнал доступности (то же, что llm:probe-routes)';

    public function handle(): int
    {
        return $this->call('llm:probe-routes', $this->option('probe') ? [] : ['--show' => true]);
    }
}
