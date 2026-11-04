<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Services\Retro\RetroAttachmentFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Скачивает вложения восстановленных писем, только на чтение почты.
 * documents — письма с КП и счетами; open — все письма с вложениями в открытых заявках.
 * Можно прервать и запустить снова.
 *
 *   php artisan retro:attachments --database=MyLift_retro --scope=documents --mailbox=info@myzip.ru
 *   php artisan retro:attachments --database=MyLift_retro --link-only
 */
class RetroAttachmentsCommand extends Command
{
    protected $signature = 'retro:attachments
        {--database= : Имя базы (обязательно)}
        {--scope=documents : documents или open}
        {--mailbox=* : Ящик; без параметра — все по очереди}
        {--link-only : Только привязать уже скачанные файлы к КП и счетам}';

    protected $description = 'Скачивает вложения восстановленных писем (только чтение почты)';

    public function handle(RetroAttachmentFetcher $fetcher): int
    {
        ini_set('memory_limit', '-1');
        $database = (string) $this->option('database');
        $scope = (string) $this->option('scope');
        if ($database === '' || ! in_array($scope, [RetroAttachmentFetcher::SCOPE_DOCUMENTS, RetroAttachmentFetcher::SCOPE_OPEN], true)) {
            $this->error('Нужны --database=MyLift_retro и --scope=documents|open');

            return self::FAILURE;
        }
        $connection = (string) config('database.default');
        config(["database.connections.$connection.database" => $database]);
        DB::purge($connection);

        if (! $this->option('link-only')) {
            $query = Mailbox::query()->orderBy('id');
            if ($this->option('mailbox') !== []) {
                $query->whereIn('email', $this->option('mailbox'));
            }
            foreach ($query->get() as $mailbox) {
                if (! $mailbox->accessToken()) {
                    continue;
                }
                $result = $fetcher->fetch($mailbox, $scope, fn (string $line) => $this->line(date('H:i:s ').$mailbox->email.' '.$line));
                $this->info(sprintf('%s: писем %d, файлов %d, %d МБ, не отдано %d', $mailbox->email, $result['messages'], $result['files'], $result['bytes'] / 1048576, $result['missing']));
            }
        }
        $this->info('Привязано документов: '.$fetcher->linkDocuments());

        return self::SUCCESS;
    }
}
