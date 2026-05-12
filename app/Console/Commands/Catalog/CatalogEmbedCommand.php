<?php

namespace App\Console\Commands\Catalog;

use App\Services\Catalog\CatalogEmbeddingService;
use Illuminate\Console\Command;

/**
 * Phase 2 use-case C: ручной запуск sync эмбеддингов каталога.
 *
 * Применение:
 *   php artisan catalog:embed              # incremental (только изменившиеся)
 *   php artisan catalog:embed --all        # force re-embed всего каталога
 *                                          # (после смены модели или формулы text)
 *
 * Выводит counters: checked / synced / skipped / errors / tokens_used.
 *
 * Запускается отдельным процессом, не job'ом — для интерактивной первичной
 * заливки на ~30k items (~$0.05, ~3-5 минут).
 */
class CatalogEmbedCommand extends Command
{
    protected $signature = 'catalog:embed
        {--all : Перегенерировать все эмбеддинги, даже если source_hash совпадает}';

    protected $description = 'Phase 2: синхронизация эмбеддингов каталога с OpenAI для use-case C (name-vector match).';

    public function handle(CatalogEmbeddingService $svc): int
    {
        $force = (bool) $this->option('all');
        $this->info('Sync эмбеддингов каталога' . ($force ? ' (--all force)' : ' (incremental)') . '...');

        $stats = $svc->syncAll($force);

        $this->table(
            ['metric', 'value'],
            [
                ['checked', (string) $stats['checked']],
                ['synced', (string) $stats['synced']],
                ['skipped', (string) $stats['skipped']],
                ['errors', (string) $stats['errors']],
                ['tokens_used', (string) $stats['tokens_used']],
            ],
        );

        return self::SUCCESS;
    }
}
