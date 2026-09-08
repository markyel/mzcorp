<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Services\Mail\ImapFolderSyncService;
use Illuminate\Console\Command;

/**
 * Разовый полный проход по пользовательским папкам личного ящика на сервере:
 * письма, лежащие там в Яндексе, переселяются в те же папки в mzCorp
 * (матч по Message-ID). См. ImapFolderSyncService::backfill.
 *
 *   php artisan mail:folders-backfill --mailbox=13
 *   php artisan mail:folders-backfill --mailbox=13 --folder='&BBIE...-'   (raw IMAP-путь)
 */
class MailFoldersBackfillCommand extends Command
{
    protected $signature = 'mail:folders-backfill
        {--mailbox= : id личного ящика (обязательно)}
        {--folder= : только эта папка (raw IMAP-путь из mailbox_folders.imap_path)}
        {--chunk=100 : сколько заголовков за один FETCH}';

    protected $description = 'Полный проход по папкам ящика на IMAP-сервере: переселить известные письма в их папки';

    public function handle(ImapFolderSyncService $svc): int
    {
        $mailbox = Mailbox::query()->find((int) $this->option('mailbox'));
        if (! $mailbox) {
            $this->error('--mailbox=<id> обязателен.');

            return self::INVALID;
        }
        if (! $svc->isServerSynced($mailbox)) {
            $this->error("Ящик {$mailbox->email} не синхронизирует папки с сервером (не личный / без владельца / выключено).");

            return self::FAILURE;
        }

        $started = microtime(true);
        $stats = $svc->backfill(
            $mailbox,
            $this->option('folder') ?: null,
            function (string $folder, int $done, int $total, int $moved) {
                $this->line(sprintf('  %s: %d/%d просмотрено, %d переселено', $folder, $done, $total, $moved));
            },
            max(10, (int) $this->option('chunk')),
        );

        $this->info(sprintf(
            'Готово за %d с: просмотрено %d, переселено %d, неизвестных (нет в БД) %d.',
            (int) (microtime(true) - $started),
            $stats['scanned'],
            $stats['moved'],
            $stats['unknown'],
        ));

        return self::SUCCESS;
    }
}
