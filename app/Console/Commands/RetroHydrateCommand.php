<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Services\Retro\RetroBodyHydrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Подтягивает текст восстановленных писем заявок, только на чтение. Вложения
 * скачиваются позже, по кнопке в карточке. Можно прервать и запустить снова.
 *
 *   php artisan retro:hydrate --database=MyLift_retro --mailbox=info@myzip.ru
 */
class RetroHydrateCommand extends Command
{
    protected $signature = 'retro:hydrate
        {--database= : Имя базы (обязательно)}
        {--mailbox=* : Ящик; без параметра — все по очереди}';

    protected $description = 'Подтягивает текст восстановленных писем заявок (только чтение почты)';

    public function handle(RetroBodyHydrator $hydrator): int
    {
        $database = (string) $this->option('database');
        if ($database === '') {
            $this->error('Укажите базу: --database=MyLift_retro');

            return self::FAILURE;
        }
        $connection = (string) config('database.default');
        config(["database.connections.$connection.database" => $database]);
        DB::purge($connection);

        $query = Mailbox::query()->orderBy('id');
        if ($this->option('mailbox') !== []) {
            $query->whereIn('email', $this->option('mailbox'));
        }
        foreach ($query->get() as $mailbox) {
            if (! $mailbox->accessToken()) {
                continue;
            }
            $result = $hydrator->hydrate($mailbox, fn (string $line) => $this->line(date('H:i:s ').$mailbox->email.' '.$line));
            $this->info(sprintf('%s: загружено %d, нет на сервере %d', $mailbox->email, $result['updated'], $result['missing']));
        }

        return self::SUCCESS;
    }
}
