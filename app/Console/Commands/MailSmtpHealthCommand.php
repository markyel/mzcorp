<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Services\Mail\SmtpReachabilityProbe;
use Illuminate\Console\Command;

/**
 * Фоновая проверка SMTP-серверов активных ящиков (SmtpReachabilityProbe).
 * Раз в минуту: отправка письма читает готовое состояние и не ждёт
 * таймаута, а при «недоступен» сразу уходит резервным каналом.
 *
 *   php artisan mail:smtp-health
 */
class MailSmtpHealthCommand extends Command
{
    protected $signature = 'mail:smtp-health';

    protected $description = 'Проверить доступность SMTP-серверов ящиков (для мгновенного выбора канала отправки)';

    public function handle(SmtpReachabilityProbe $probe): int
    {
        $targets = Mailbox::query()
            ->where('is_active', true)
            ->whereNotNull('smtp_host')
            ->get(['smtp_host', 'smtp_port'])
            ->map(fn (Mailbox $m) => [(string) $m->smtp_host, (int) $m->smtp_port])
            ->unique(fn (array $t) => strtolower($t[0]).':'.$t[1])
            ->values();

        foreach ($targets as [$host, $port]) {
            $up = $probe->check($host, $port);
            $this->line(sprintf('%s:%d %s', $host, $port, $up ? 'доступен' : 'НЕДОСТУПЕН'));
        }

        return self::SUCCESS;
    }
}
