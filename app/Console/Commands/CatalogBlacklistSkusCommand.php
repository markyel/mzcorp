<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Models\CatalogSkuBlacklist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Занести M-артикулы в чёрный список каталога и исключить их позиции.
 *
 * Делает три вещи (идемпотентно):
 *   1) upsert SKU в `catalog_sku_blacklist` — список переживает импорты, и
 *      CatalogImportService выкидывает эти SKU из снапшота (is_active не
 *      вернётся в true);
 *   2) существующим catalog_items с этими SKU → is_active=false (немедленное
 *      исключение из поиска/матчинга/фида/снабжения, не дожидаясь импорта);
 *   3) заявочные позиции, привязанные к ним, → отвязать (catalog_item_id=null,
 *      match_path=null, убрать catalog_match из payload), чтобы перематчились
 *      на канонический артикул, а не на дубль (--keep-matches отключает).
 *
 * Источник SKU — текстовый файл (по одному в строке; из строки берётся первый
 * M-артикул). Пример: php artisan catalog:blacklist-skus --file=/tmp/skus.txt
 */
class CatalogBlacklistSkusCommand extends Command
{
    protected $signature = 'catalog:blacklist-skus
        {--file= : путь к файлу со списком SKU (по одному в строке)}
        {--reason=dubl_mdb : причина для записи в blacklist}
        {--keep-matches : НЕ отвязывать позиции заявок от blacklisted-каталога}
        {--dry-run : показать, что произойдёт, без изменений}';

    protected $description = 'Чёрный список M-артикулов: исключить дубли из каталога и не возвращать при импортах.';

    public function handle(): int
    {
        $file = (string) $this->option('file');
        if ($file === '' || ! is_file($file)) {
            $this->error('Укажите существующий --file со списком SKU.');

            return self::FAILURE;
        }

        $skus = [];
        foreach (file($file) as $line) {
            if (preg_match('/M\d{3,}/i', (string) $line, $m) === 1) {
                $skus[mb_strtoupper($m[0])] = true;
            }
        }
        $skus = array_keys($skus);
        if ($skus === []) {
            $this->error('В файле не найдено ни одного M-артикула.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $reason = (string) $this->option('reason');

        $inCatalog = CatalogItem::whereIn('sku', $skus)->count();
        $activeInCatalog = CatalogItem::whereIn('sku', $skus)->where('is_active', true)->count();
        $catalogIds = CatalogItem::whereIn('sku', $skus)->pluck('id')->all();
        $matchedItems = $catalogIds === [] ? 0
            : DB::table('request_items')->whereIn('catalog_item_id', $catalogIds)->count();
        $alreadyBlacklisted = CatalogSkuBlacklist::whereIn('sku', $skus)->count();

        $this->info(sprintf('SKU в файле: %d', count($skus)));
        $this->info(sprintf('  уже в blacklist: %d', $alreadyBlacklisted));
        $this->info(sprintf('  есть в каталоге: %d (из них активны: %d)', $inCatalog, $activeInCatalog));
        $this->info(sprintf('  привязано позиций заявок: %d%s', $matchedItems,
            $this->option('keep-matches') ? ' (оставляем как есть)' : ' (будут отвязаны)'));

        if ($dryRun) {
            $this->warn('--dry-run: изменений не внесено.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($skus, $reason, $catalogIds) {
            // 1) blacklist upsert
            $now = now();
            foreach (array_chunk($skus, 500) as $chunk) {
                CatalogSkuBlacklist::query()->upsert(
                    array_map(fn ($s) => ['sku' => $s, 'reason' => $reason, 'created_at' => $now, 'updated_at' => $now], $chunk),
                    ['sku'],
                    ['reason', 'updated_at'],
                );
            }

            // 2) деактивация существующих
            CatalogItem::whereIn('sku', $skus)->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => $now]);

            // 3) отвязка позиций заявок → перематч на канонический
            if (! $this->option('keep-matches') && $catalogIds !== []) {
                DB::table('request_items')
                    ->whereIn('catalog_item_id', $catalogIds)
                    ->update([
                        'catalog_item_id' => null,
                        'match_path' => null,
                        'quality_assessment_payload' => DB::raw(
                            "COALESCE(quality_assessment_payload, '{}'::jsonb) - 'catalog_match'"
                        ),
                        'updated_at' => $now,
                    ]);
            }
        });

        $this->info('Готово: SKU занесены в blacklist, позиции каталога деактивированы'
            . ($this->option('keep-matches') ? '.' : ', привязки заявок сброшены.'));
        $this->line('Повторные импорты каталога больше не вернут эти позиции (фильтр снапшота в CatalogImportService).');

        return self::SUCCESS;
    }
}
