<?php

namespace App\Console\Commands;

use App\Services\Catalog\CatalogSupplierPriceImportService;
use Illuminate\Console\Command;

/**
 * Импорт цен закупки из выгрузки 1С «Номенклатура (история цен)» в
 * catalog_supplier_prices. Без --apply — только сводка.
 *
 *   php artisan catalog:import-supplier-prices storage/app/imports/nomenclature-prices.xlsx
 *   php artisan catalog:import-supplier-prices storage/app/imports/nomenclature-prices.xlsx --apply
 */
class CatalogImportSupplierPricesCommand extends Command
{
    protected $signature = 'catalog:import-supplier-prices {file : Путь к .xlsx} {--apply : Записать в базу}';

    protected $description = 'Import 1C purchase price history (first/last purchase per SKU) into catalog_supplier_prices';

    public function handle(CatalogSupplierPriceImportService $svc): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }
        ini_set('memory_limit', '3G');

        $res = $svc->parse($path);
        $s = $res['stats'];
        $this->info("Позиций: {$s['positions']}, цен закупки: {$s['points']}, из них позиций нашего каталога: {$s['in_catalog']}, поставщик сопоставлен с реестром: {$s['supplier_matched']}.");
        $this->line('Не сопоставлены с реестром (топ-20, по числу цен):');
        foreach (array_slice($res['unmatched_suppliers'], 0, 20, true) as $name => $n) {
            $this->line("  {$n}\t{$name}");
        }

        if (! $this->option('apply')) {
            $this->warn('Сухой прогон. Для записи — --apply.');

            return self::SUCCESS;
        }

        $written = $svc->apply($res['rows'], basename($path));
        $this->info("Записано: {$written}.");

        return self::SUCCESS;
    }
}
