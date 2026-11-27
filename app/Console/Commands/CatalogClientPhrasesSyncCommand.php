<?php

namespace App\Console\Commands;

use App\Services\Catalog\CatalogClientPhraseService;
use Illuminate\Console\Command;

/**
 * Словарь «как клиенты называли позицию» (catalog_client_phrases): подтверждённые позиции заявок — внести,
 * позиции со сменившейся карточкой — убрать. По расписанию раз в час; первый запуск разбирает всю историю.
 *
 *   php artisan catalog:client-phrases-sync
 *   php artisan catalog:client-phrases-sync --limit=10000
 */
class CatalogClientPhrasesSyncCommand extends Command
{
    protected $signature = 'catalog:client-phrases-sync {--limit=2000 : сколько новых позиций за запуск}';

    protected $description = 'Формулировки клиентов к карточкам каталога для подбора по названию';

    public function handle(CatalogClientPhraseService $phrases): int
    {
        $r = $phrases->sync(max(1, (int) $this->option('limit')));
        $this->info(sprintf('Добавлено формулировок: %d, убрано: %d.', $r['added'], $r['removed']));

        return self::SUCCESS;
    }
}
