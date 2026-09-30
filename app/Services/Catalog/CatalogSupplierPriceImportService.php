<?php

namespace App\Services\Catalog;

use App\Models\CatalogSupplierPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Импорт выгрузки 1С «Номенклатура (история цен)»: по каждой позиции первая и
 * последняя закупка — поставщик, дата, цена EXW, валюта.
 *
 * Заголовки в выгрузке повторяются («Поставщик», «Период» дважды), поэтому
 * колонки берём по позиции: A артикул, B наименование, C–F первая закупка,
 * G–J последняя. Позиция без цены (0) пропускается. Поставщик хранится как в
 * 1С и сопоставляется с реестром по названию.
 */
class CatalogSupplierPriceImportService
{
    private const COLUMNS = [
        CatalogSupplierPrice::KIND_FIRST => ['supplier' => 2, 'date' => 3, 'price' => 4, 'currency' => 5],
        CatalogSupplierPrice::KIND_LAST => ['supplier' => 6, 'date' => 7, 'price' => 8, 'currency' => 9],
    ];

    /**
     * @return array{rows: list<array<string, mixed>>, stats: array<string, int>, unmatched_suppliers: array<string, int>}
     */
    public function parse(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheet(0)->toArray(null, false, false, false);
        $header = array_shift($sheet) ?? [];
        if (mb_strtolower(trim((string) ($header[0] ?? ''))) !== 'артикул') {
            throw new \RuntimeException('Не похоже на выгрузку «Номенклатура (история цен)»: первая колонка не «Артикул».');
        }

        $catalog = DB::table('catalog_items')->pluck('id', 'sku')->all();
        $suppliers = $this->supplierIndex();
        $rows = [];
        $unmatched = [];
        $stats = ['positions' => 0, 'points' => 0, 'in_catalog' => 0, 'supplier_matched' => 0];

        foreach ($sheet as $line) {
            $sku = mb_strtoupper(trim((string) ($line[0] ?? '')));
            if ($sku === '') {
                continue;
            }
            $stats['positions']++;
            foreach (self::COLUMNS as $kind => $c) {
                $supplier = trim((string) ($line[$c['supplier']] ?? ''));
                $price = (float) ($line[$c['price']] ?? 0);
                if ($supplier === '' || $price <= 0) {
                    continue;
                }
                $supplierId = $suppliers[self::normalize($supplier)] ?? $suppliers[self::normalize(self::head($supplier))] ?? null;
                if ($supplierId === null) {
                    $unmatched[$supplier] = ($unmatched[$supplier] ?? 0) + 1;
                } else {
                    $stats['supplier_matched']++;
                }
                $catalogId = $catalog[$sku] ?? null;
                $stats['points']++;
                $stats['in_catalog'] += $catalogId !== null ? 1 : 0;
                $rows[] = [
                    'sku' => $sku,
                    'catalog_item_id' => $catalogId,
                    'kind' => $kind,
                    'supplier_name_1c' => mb_substr($supplier, 0, 500),
                    'supplier_id' => $supplierId,
                    'priced_at' => self::date($line[$c['date']] ?? null),
                    'price' => round($price, 4),
                    'currency' => ($cur = trim((string) ($line[$c['currency']] ?? ''))) !== '' ? mb_substr($cur, 0, 8) : null,
                ];
            }
        }
        arsort($unmatched);

        return ['rows' => $rows, 'stats' => $stats, 'unmatched_suppliers' => $unmatched];
    }

    /**
     * Записать разобранное: по артикулу и виду цены — одна строка, повторный
     * импорт обновляет.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function apply(array $rows, string $fileName): int
    {
        $now = now();
        $written = 0;
        foreach (array_chunk($rows, 1000) as $chunk) {
            $payload = array_map(fn (array $r) => $r + [
                'source' => '1c',
                'import_file' => mb_substr($fileName, 0, 255),
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk);
            CatalogSupplierPrice::query()->upsert(
                $payload,
                ['sku', 'kind', 'source'],
                ['catalog_item_id', 'supplier_name_1c', 'supplier_id', 'priced_at', 'price', 'currency', 'import_file', 'updated_at'],
            );
            $written += count($chunk);
        }

        return $written;
    }

    /** Реестр поставщиков: нормализованное название (полное и до скобки) → id. */
    private function supplierIndex(): array
    {
        $index = [];
        foreach (DB::table('suppliers')->whereNotNull('name')->where('name', '!=', '')->get(['id', 'name']) as $s) {
            $index[self::normalize($s->name)] ??= (int) $s->id;
            $index[self::normalize(self::head($s->name))] ??= (int) $s->id;
        }

        return $index;
    }

    /** Название до первой скобки: «OTI  (ES Escalator Parts Company)» → «OTI». */
    private static function head(string $name): string
    {
        $pos = mb_strpos($name, '(');

        return $pos === false || $pos === 0 ? $name : mb_substr($name, 0, $pos);
    }

    public static function normalize(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($name)) ?? '');
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }
        try {
            return is_numeric($value)
                ? Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString()
                : Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
