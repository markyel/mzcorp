<?php

namespace App\Console\Commands;

use App\Jobs\Kb\ResolveKbJob;
use App\Jobs\Mail\ParseRequestItemsJob;
use App\Models\Request;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Перепрогон парсинга позиций + KB-resolve для одной заявки.
 *
 * Используется после изменения промптов / сервисов парсинга, чтобы
 * применить новые правила к уже существующей заявке без ожидания
 * нового inbound-письма.
 *
 * Запуск:
 *   php artisan request:reparse M-2026-1147               — sync, в текущей сессии
 *   php artisan request:reparse M-2026-1147 --queue       — через очередь
 *   php artisan request:reparse M-2026-1147 --reset       — сбросить items и
 *                                                            распарсить с нуля
 */
class RequestReparse extends Command
{
    protected $signature = 'request:reparse {code} {--queue : через очередь вместо sync} {--reset : удалить items и распарсить заново} {--no-kb : пропустить ResolveKbJob}';

    protected $description = 'Перепрогон ParseRequestItemsJob + ResolveKbJob для одной заявки';

    public function handle(): int
    {
        $code = (string) $this->argument('code');
        $req = Request::where('internal_code', $code)->first();
        if (! $req) {
            $this->error("Заявка {$code} не найдена");
            return self::FAILURE;
        }
        if (! $req->email_message_id) {
            $this->error("У заявки {$code} нет связанного email_message_id — нечего перепарсить");
            return self::FAILURE;
        }

        $reset = (bool) $this->option('reset');
        $async = (bool) $this->option('queue');
        $skipKb = (bool) $this->option('no-kb');

        $this->line("Заявка #{$req->id} {$req->internal_code} (email_message_id={$req->email_message_id})");
        $this->line('Режим: '.($async ? 'queue' : 'sync').', reset='.($reset ? 'yes' : 'no').', no-kb='.($skipKb ? 'yes' : 'no'));
        $this->line('');

        $this->line('--- 1. ParseRequestItemsJob (force=true'.($reset ? ', reset=true' : '').') ---');
        try {
            $job = new ParseRequestItemsJob($req->email_message_id, true, $reset);
            if ($async) {
                Bus::dispatch($job);
                $this->info('  поставлен в очередь');
            } else {
                Bus::dispatchSync($job);
                $this->info('  выполнен sync');
            }
        } catch (\Throwable $e) {
            $this->error('  ОШИБКА: '.$e->getMessage());
            return self::FAILURE;
        }
        $this->line('');

        if ($skipKb) {
            $this->line('--- 2. ResolveKbJob пропущен (--no-kb) ---');
            return self::SUCCESS;
        }

        $this->line('--- 2. ResolveKbJob ---');
        try {
            $job = new ResolveKbJob($req->id);
            if ($async) {
                Bus::dispatch($job);
                $this->info('  поставлен в очередь');
            } else {
                Bus::dispatchSync($job);
                $this->info('  выполнен sync');
            }
        } catch (\Throwable $e) {
            $this->error('  ОШИБКА: '.$e->getMessage());
            return self::FAILURE;
        }
        $this->line('');

        $this->line('=== Готово. Проверь UI или запусти inspect:request '.$code.' ===');
        return self::SUCCESS;
    }
}
