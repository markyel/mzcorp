<?php

namespace App\Services\Catalog;

use App\Jobs\Suppliers\ReconcilePriceRefreshJob;
use App\Models\CatalogImport;
use App\Models\CatalogItem;
use App\Models\CatalogManualPrice;
use App\Models\CatalogManualSync;
use App\Models\CatalogPriceChange;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Ручная синхронизация каталога: секретарь выгружает из 1С позиции с актуализированной ценой
 * (Артикул, Наименование, Цена закупки, Маржа%, Цена продажи, Цена продажи (мин), Дата цены
 * продажи, остатки, ячейка) и загружает в раздел «Синхронизация каталога».
 *
 * Разбор → предпросмотр «было → стало» → «Применить». Обновляются только позиции файла: цена,
 * минимальная, закупочная, «цена актуальна» и свободный остаток на складе; история цен — как у
 * планового импорта (catalog_price_changes), заявки, ждущие цену, подхватывает
 * ReconcilePriceRefreshJob. Цена держится (CatalogManualPrice), пока выгрузка mylift отдаёт старую,
 * см. CatalogImportService::holdManualPrices. Остаток не держим: следующая новая выгрузка mylift —
 * более свежий снимок склада. «В пути» из файла не берём: в нём нет дат прихода, а витрины и КП
 * показывают партии в пути по датам.
 *
 * Файл бывает в двух видах: числа числами или текстом «2 727,00», заголовки с пробелами или без.
 */
class CatalogManualSyncService
{
    /** Заголовок (без пробелов, в нижнем регистре) → поле. */
    private const HEADERS = [
        'артикул' => 'sku',
        'наименование' => 'name',
        'ценазакупки' => 'purchase_price',
        'маржа%' => 'margin',
        'ценапродажи' => 'price',
        'ценапродажи(мин)' => 'price_min',
        'датаценыпродажи' => 'price_date',
        'веб' => 'web',
        'наскладе<свободно>' => 'stock',
    ];

    /**
     * Строки файла: [{line, sku, name, price, price_min, purchase_price, margin, price_date, error}].
     *
     * @return list<array<string, mixed>>
     */
    public function parse(string $absolutePath): array
    {
        $sheet = IOFactory::load($absolutePath)->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);

        // Строка заголовков — та, где есть «Артикул» (сверху могут быть шапки 1С).
        $map = null;
        $start = 0;
        foreach (array_slice($rows, 0, 15) as $i => $row) {
            $candidate = [];
            foreach ($row as $col => $title) {
                $key = self::headerKey((string) $title);
                if (isset(self::HEADERS[$key])) {
                    $candidate[self::HEADERS[$key]] = $col;
                }
            }
            if (isset($candidate['sku'])) {
                $map = $candidate;
                $start = $i + 1;
                break;
            }
        }
        if ($map === null || ! isset($map['price'])) {
            throw new RuntimeException('Не нашёл колонки «Артикул» и «Цена продажи» — проверьте, что это выгрузка 1С с ценами.');
        }

        $out = [];
        $seen = [];
        foreach (array_slice($rows, $start, null, true) as $i => $row) {
            $sku = mb_strtoupper(trim((string) ($row[$map['sku']] ?? '')));
            if ($sku === '') {
                continue;
            }
            $item = [
                'line' => $i + 1,
                'sku' => $sku,
                'name' => trim((string) ($row[$map['name'] ?? -1] ?? '')),
                'price' => self::number($row[$map['price']] ?? null),
                'price_min' => isset($map['price_min']) ? self::number($row[$map['price_min']] ?? null) : null,
                'purchase_price' => isset($map['purchase_price']) ? self::number($row[$map['purchase_price']] ?? null) : null,
                'margin' => isset($map['margin']) ? self::number($row[$map['margin']] ?? null) : null,
                'price_date' => isset($map['price_date']) ? self::date($row[$map['price_date']] ?? null) : null,
                'stock' => isset($map['stock']) && ($st = self::number($row[$map['stock']] ?? null)) !== null ? max(0, (int) round($st)) : null,
                'error' => null,
            ];
            if ($item['price'] === null || $item['price'] <= 0) {
                $item['error'] = 'нет цены продажи';
            } elseif (isset($seen[$sku])) {
                $item['error'] = 'артикул повторяется в файле (строка '.$seen[$sku].')';
            }
            $seen[$sku] ??= $item['line'];
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Разбор с каталогом: по каждой строке — было/стало и статус (changed / same / unknown / invalid).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function preview(array $rows): array
    {
        $catalog = CatalogItem::query()->whereIn('sku', array_column($rows, 'sku'))
            ->get(['id', 'sku', 'name', 'price', 'price_min', 'purchase_price', 'is_price_actual', 'is_active', 'stock_available'])
            ->keyBy(fn ($c) => mb_strtoupper(trim((string) $c->sku)));

        return array_map(function (array $r) use ($catalog) {
            $c = $catalog->get($r['sku']);
            $r['catalog_item_id'] = $c?->id;
            $r['catalog_name'] = $c?->name;
            $r['old'] = $c ? [
                'price' => $c->price !== null ? (float) $c->price : null,
                'price_min' => $c->price_min !== null ? (float) $c->price_min : null,
                'purchase_price' => $c->purchase_price !== null ? (float) $c->purchase_price : null,
                'actual' => (bool) $c->is_price_actual,
                'stock' => $c->stock_available !== null ? (int) $c->stock_available : null,
            ] : null;
            $r['status'] = match (true) {
                $r['error'] !== null => CatalogManualSync::ROW_INVALID,
                $c === null => CatalogManualSync::ROW_UNKNOWN,
                self::differs($r['old']['price'], $r['price'])
                    || ($r['price_min'] !== null && self::differs($r['old']['price_min'], $r['price_min']))
                    || ($r['purchase_price'] !== null && self::differs($r['old']['purchase_price'], $r['purchase_price']))
                    || ($r['stock'] ?? null) !== null && $r['stock'] !== $r['old']['stock']
                    || ! $r['old']['actual'] => CatalogManualSync::ROW_CHANGED,
                default => CatalogManualSync::ROW_SAME,
            };

            return $r;
        }, $rows);
    }

    /** Загрузить файл: разбор и предпросмотр — в журнал, применяет человек кнопкой. */
    public function upload(string $storedPath, string $originalName, User $by): CatalogManualSync
    {
        $rows = $this->preview($this->parse(\Illuminate\Support\Facades\Storage::disk('local')->path($storedPath)));
        $count = fn (string $status) => count(array_filter($rows, fn ($r) => $r['status'] === $status));

        return CatalogManualSync::create([
            'user_id' => $by->id,
            'original_name' => mb_substr($originalName, 0, 255),
            'file_path' => $storedPath,
            'status' => CatalogManualSync::STATUS_PARSED,
            'rows_total' => count($rows),
            'rows_matched' => $count(CatalogManualSync::ROW_CHANGED) + $count(CatalogManualSync::ROW_SAME),
            'rows_changed' => $count(CatalogManualSync::ROW_CHANGED),
            'rows_unknown' => $count(CatalogManualSync::ROW_UNKNOWN),
            'rows_invalid' => $count(CatalogManualSync::ROW_INVALID),
            'rows' => $rows,
        ]);
    }

    /**
     * Применить разобранную загрузку. Сверяем с каталогом заново (между разбором и «Применить»
     * плановый импорт мог поменять цены) и пишем только изменившееся.
     *
     * @return array{updated: int, became_actual: int, held: int}
     */
    public function apply(CatalogManualSync $sync, User $by): array
    {
        $lock = \Illuminate\Support\Facades\Cache::lock('catalog-manual-sync:'.$sync->id, 120);
        if (! $lock->get()) {
            throw new RuntimeException('Эта загрузка уже применяется.');
        }
        try {
            $sync->refresh();
            if ($sync->status !== CatalogManualSync::STATUS_PARSED) {
                throw new RuntimeException('Загрузка уже '.($sync->status === CatalogManualSync::STATUS_APPLIED ? 'применена' : 'закрыта').'.');
            }
            $rows = $this->preview(array_values(array_filter((array) $sync->rows, fn ($r) => ($r['error'] ?? null) === null)));
            $valid = array_values(array_filter($rows, fn ($r) => $r['catalog_item_id'] !== null));
            $holdDays = max(1, (int) config('services.catalog_manual_sync.hold_days', 14));
            $now = Carbon::now();
            $becameActual = [];
            $updated = 0;

            DB::transaction(function () use ($sync, $by, $valid, $holdDays, $now, &$becameActual, &$updated) {
                $import = CatalogImport::create([
                    'mode' => 'manual',
                    'source' => 'manual_1c_excel',
                    'rows_total' => count((array) $sync->rows),
                ]);
                foreach ($valid as $r) {
                    $old = $r['old'];
                    if ($r['status'] === CatalogManualSync::ROW_CHANGED) {
                        $fields = ['price' => $r['price'], 'is_price_actual' => true, 'last_import_id' => $import->id, 'updated_at' => $now];
                        if ($r['price_min'] !== null) {
                            $fields['price_min'] = $r['price_min'];
                        }
                        if ($r['purchase_price'] !== null) {
                            $fields['purchase_price'] = $r['purchase_price'];
                        }
                        if (($r['stock'] ?? null) !== null && $r['stock'] !== $old['stock']) {
                            $fields['stock_available'] = $r['stock'];
                            // Как у планового импорта: «на складе с» — с момента, когда появился остаток.
                            if ($r['stock'] <= 0) {
                                $fields['in_stock_since'] = null;
                            } elseif ((int) ($old['stock'] ?? 0) <= 0) {
                                $fields['in_stock_since'] = $now;
                            }
                        }
                        CatalogItem::query()->whereKey($r['catalog_item_id'])->update($fields);
                        $updated++;
                        if (! $old['actual']) {
                            $becameActual[] = (int) $r['catalog_item_id'];
                        }
                        $newMin = $r['price_min'] ?? $old['price_min'];
                        if (self::differs($old['price'], $r['price']) || self::differs($old['price_min'], $newMin)) {
                            CatalogPriceChange::create([
                                'catalog_item_id' => $r['catalog_item_id'], 'sku' => $r['sku'],
                                'old_price' => $old['price'], 'new_price' => $r['price'],
                                'old_price_min' => $old['price_min'], 'new_price_min' => $newMin,
                                'import_id' => $import->id, 'changed_at' => $now,
                            ]);
                        }
                    }
                    // Держим цену и для совпавших: выгрузка mylift могла ещё не догнать 1С.
                    CatalogManualPrice::query()->updateOrCreate(['catalog_item_id' => $r['catalog_item_id']], [
                        'sku' => $r['sku'], 'price' => $r['price'], 'price_min' => $r['price_min'], 'purchase_price' => $r['purchase_price'],
                        'price_date' => $r['price_date'], 'catalog_manual_sync_id' => $sync->id, 'hold_until' => $now->copy()->addDays($holdDays),
                    ]);
                }
                $import->forceFill(['rows_updated' => $updated, 'rows_unchanged' => count($valid) - $updated, 'duration_ms' => 0])->save();
                $sync->forceFill([
                    'status' => CatalogManualSync::STATUS_APPLIED, 'rows' => $sync->rows, 'catalog_import_id' => $import->id,
                    'applied_by_user_id' => $by->id, 'applied_at' => $now,
                ])->save();
            });

            if ($becameActual !== []) {
                // Заявки, ждущие обновления цены, и рабочие заявки с этими позициями — как после планового импорта.
                ReconcilePriceRefreshJob::dispatch($becameActual);
            }
            Log::info('CatalogManualSync: applied', ['sync_id' => $sync->id, 'updated' => $updated, 'became_actual' => count($becameActual), 'by' => $by->id]);

            return ['updated' => $updated, 'became_actual' => count($becameActual), 'held' => count($valid)];
        } finally {
            $lock->release();
        }
    }

    public static function headerKey(string $title): string
    {
        return mb_strtolower((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $title));
    }

    /** «2 727,00», «2727», 2727.0 → 2727.0; пусто и мусор → null. */
    public static function number(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = str_replace(',', '.', (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', (string) $v));

        return $s !== '' && is_numeric($s) ? (float) $s : null;
    }

    /** Дата Excel (число 46311) или «16.10.2026» → «2026-10-16». */
    public static function date(mixed $v): ?string
    {
        if (is_int($v) || is_float($v) || (is_string($v) && is_numeric(trim($v)))) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        try {
            return Carbon::createFromFormat('d.m.Y', $s)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function differs(?float $a, ?float $b): bool
    {
        if ($a === null || $b === null) {
            return $a !== $b;
        }

        return abs($a - $b) > 0.005;
    }
}
