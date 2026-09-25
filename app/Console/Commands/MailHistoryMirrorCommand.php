<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Services\Mail\MailHistoryMirrorService;
use Illuminate\Console\Command;

/**
 * Зеркало истории личных ящиков менеджеров: письма, которых у нас нет (до
 * подключения ящика, в пользовательских папках Яндекса), заводятся шапками —
 * тело скачивается при открытии. См. MailHistoryMirrorService.
 *
 *   php artisan mail:history-mirror                       # все ящики, до конца
 *   php artisan mail:history-mirror --mailbox=13          # один ящик
 *   php artisan mail:history-mirror --seconds=240 --budget=3000   # поддержка по расписанию
 */
class MailHistoryMirrorCommand extends Command
{
    protected $signature = 'mail:history-mirror
        {--mailbox=* : id ящиков (по умолчанию — все личные ящики менеджеров)}
        {--budget=0 : максимум писем на ящик за прогон (0 — без ограничения)}
        {--seconds=0 : лимит времени на ящик, с (0 — без ограничения)}';

    protected $description = 'Завести историю личных ящиков менеджеров шапками писем (тело — при открытии)';

    public function handle(MailHistoryMirrorService $svc): int
    {
        $ids = array_filter(array_map('intval', (array) $this->option('mailbox')));
        $mailboxes = $ids !== []
            ? Mailbox::query()->whereIn('id', $ids)->orderBy('id')->get()->filter(fn ($m) => $svc->isMirrored($m))
            : $svc->mailboxes();

        if ($mailboxes->isEmpty()) {
            $this->warn('Нет ящиков для зеркала (личные ящики менеджеров, синхронизируемые mzCorp).');

            return self::SUCCESS;
        }

        foreach ($mailboxes as $mailbox) {
            $started = microtime(true);
            $this->info("#{$mailbox->id} {$mailbox->email}");
            try {
                $stats = $svc->run(
                    $mailbox,
                    max(0, (int) $this->option('budget')),
                    max(0, (int) $this->option('seconds')),
                    function (string $folder, int $done, int $todo) {
                        if ($done === $todo || $done % 5000 < 500) {
                            $this->line(sprintf('  %s: %d/%d', mb_convert_encoding($folder, 'UTF-8', 'UTF7-IMAP'), $done, $todo));
                        }
                    },
                );
            } catch (\Throwable $e) {
                $this->error('  ошибка: '.$e->getMessage());

                continue;
            }
            $this->line(sprintf(
                '  готово за %d с: заведено %d, переселено %d, UID проставлен %d',
                (int) (microtime(true) - $started), $stats['imported'], $stats['rehomed'], $stats['uid_filled'],
            ));
        }

        return self::SUCCESS;
    }
}
