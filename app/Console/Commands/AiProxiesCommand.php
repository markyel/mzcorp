<?php

namespace App\Console\Commands;

use App\Services\AI\OpenAIProxyPool;
use Illuminate\Console\Command;

/**
 * Прокси к OpenAI (OPENAI_BASE_URLS): какие в работе, какие пропускаются и почему.
 * С --probe каждый проверяется крошечным эмбеддингом: отказавший пропускается, оживший
 * возвращается в работу сразу, не дожидаясь конца паузы. В расписании — раз в 5 минут.
 *
 *   php artisan ai:proxies
 *   php artisan ai:proxies --probe
 */
class AiProxiesCommand extends Command
{
    protected $signature = 'ai:proxies {--probe : проверить каждый прокси сейчас}';

    protected $description = 'Прокси к OpenAI: состояние и проверка';

    public function handle(OpenAIProxyPool $pool): int
    {
        $rows = [];
        foreach ($pool->urls() as $i => $url) {
            $probe = $this->option('probe') ? $pool->probe($url) : null;
            $down = $pool->downInfo($url);
            $rows[] = [
                $i + 1,
                $url,
                $down ? 'пропускается с '.substr($down['at'], 11, 5) : 'в работе',
                $probe ? ($probe['ok'] ? 'ok' : 'отказ').', '.$probe['detail'].', '.$probe['ms'].' мс' : '—',
                $down ? mb_substr($down['why'], 0, 80) : '',
            ];
        }

        $this->table(['#', 'Прокси', 'Состояние', 'Проверка', 'Причина'], $rows);
        $this->line('Запросы идут в: '.($pool->candidates(true)[0] ?? 'никуда — все прокси отказали, работает резерв (если настроен)'));

        return self::SUCCESS;
    }
}
