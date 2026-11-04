<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Services\Retro\RetroScanner;
use Illuminate\Console\Command;

/**
 * Читает письма ящика в индекс ретрозагрузки, только на чтение. Базу не меняет.
 * Прерванный проход продолжается с места остановки.
 *
 *   php artisan retro:scan --mailbox=info@myzip.ru
 */
class RetroScanCommand extends Command
{
    protected $signature = 'retro:scan
        {--mailbox=* : Ящик; без параметра — все с токеном}';

    protected $description = 'Читает письма ящиков в индекс ретрозагрузки (только чтение)';

    public function handle(RetroScanner $scanner): int
    {
        $query = Mailbox::query()->orderBy('id');
        if ($this->option('mailbox') !== []) {
            $query->whereIn('email', $this->option('mailbox'));
        }
        foreach ($query->get() as $mailbox) {
            if (! $mailbox->accessToken()) {
                $this->warn($mailbox->email.': нет токена, пропущен');

                continue;
            }
            $this->info($mailbox->email);
            $result = $scanner->scan($mailbox, fn (string $line) => $this->line(date('H:i:s ').$line));
            $this->info(sprintf('%s: папок %d, прочитано новых писем %d', $mailbox->email, $result['folders'], $result['read']));
        }

        return self::SUCCESS;
    }
}
