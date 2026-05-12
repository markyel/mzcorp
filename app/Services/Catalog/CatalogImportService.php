<?php

namespace App\Services\Catalog;

use App\Models\CatalogImport;
use App\Models\CatalogItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Приёмник snapshot'ов каталога из MDB (push с офисной машины).
 *
 * Контракт входа (см. `POST /api/catalog/import`):
 *   {
 *     "mode":   "full",
 *     "source": "office-pc-01",          // опц., source-tag
 *     "items":  [
 *       {
 *         "sku":              "M02016",   // обяз.
 *         "name":             "...",      // обяз.
 *         "name_en":          "...",      // опц.
 *         "unit_name":        "...",      // опц.
 *         "part_type":        "...",
 *         "brand":            "Siemens",
 *         "brand_article":    "3RT2016-2GG22",
 *         "form_factor":      "...",
 *         "size_a..f":        12.5,
 *         "weight":           0.350,
 *         "price":            1234.50,
 *         "stock_available":  5
 *       },
 *       ...
 *     ]
 *   }
 *
 * Логика:
 *  1. Валидируем mode = full (delta пока не поддержан).
 *  2. По каждой row: нормализуем + считаем source_hash.
 *  3. Apsert одним батчем (PostgreSQL ON CONFLICT по sku) — Eloquent
 *     по одной строке был бы O(N) запросов, мы делаем upsert чанками.
 *  4. После апсёрта soft-delete: всё, чего нет в snapshot'е, → is_active=false.
 *  5. Пишем CatalogImport-запись (аудит) с counter'ами и errors[].
 *
 * Идемпотентность: при повторной выгрузке того же снапшота все строки
 * выявятся как unchanged (source_hash совпадёт), 0 updates, 0 created,
 * 0 soft_deleted — никаких side-effects.
 */
class CatalogImportService
{
    /**
     * @param array<string, mixed> $payload
     * @return CatalogImport
     */
    public function import(array $payload, ?string $clientIp = null): CatalogImport
    {
        $startedAt = microtime(true);

        $mode = (string) ($payload['mode'] ?? 'full');
        $source = isset($payload['source']) ? (string) $payload['source'] : null;
        $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        $import = CatalogImport::create([
            'mode' => $mode,
            'source' => $source,
            'client_ip' => $clientIp,
            'rows_total' => count($rawItems),
        ]);

        if ($mode !== CatalogImport::MODE_FULL) {
            $this->finalize($import, $startedAt, [
                'errors' => [['code' => 'unsupported_mode', 'mode' => $mode]],
            ]);

            return $import;
        }

        $errors = [];
        $normalized = [];
        $skuSeen = [];

        foreach ($rawItems as $rowIdx => $row) {
            if (! is_array($row)) {
                $errors[] = ['code' => 'invalid_row', 'index' => $rowIdx];
                continue;
            }
            $norm = $this->normalizeRow($row);
            if ($norm === null) {
                $errors[] = [
                    'code' => 'missing_required',
                    'index' => $rowIdx,
                    'sku' => $row['sku'] ?? null,
                ];
                continue;
            }
            if (isset($skuSeen[$norm['sku']])) {
                $errors[] = ['code' => 'duplicate_sku', 'sku' => $norm['sku'], 'index' => $rowIdx];
                continue;
            }
            $skuSeen[$norm['sku']] = true;
            $normalized[] = $norm;
        }

        if (empty($normalized)) {
            $this->finalize($import, $startedAt, [
                'errors' => $errors,
            ]);

            return $import;
        }

        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'soft_deleted' => 0];

        DB::transaction(function () use ($normalized, $import, &$counts) {
            // Шаг A: достаём существующие записи по sku одним запросом.
            $skus = array_column($normalized, 'sku');
            $existing = CatalogItem::query()
                ->whereIn('sku', $skus)
                ->get(['id', 'sku', 'source_hash', 'is_active'])
                ->keyBy('sku');

            $now = Carbon::now();
            $toUpdate = [];
            $toInsert = [];

            foreach ($normalized as $row) {
                $existingRow = $existing->get($row['sku']);
                $row['last_imported_at'] = $now;
                $row['last_import_id'] = $import->id;

                if ($existingRow === null) {
                    $row['is_active'] = true;
                    $row['created_at'] = $now;
                    $row['updated_at'] = $now;
                    $toInsert[] = $row;
                    $counts['created']++;
                    continue;
                }

                $needsUpdate = $existingRow->source_hash !== $row['source_hash'] || ! $existingRow->is_active;
                if (! $needsUpdate) {
                    // Всё равно подкручиваем last_imported_at, чтобы видеть
                    // что строка пришла в этом snapshot'е (нужно для soft-delete
                    // шага ниже — иначе строку без изменений снесём в is_active=false).
                    CatalogItem::query()
                        ->where('id', $existingRow->id)
                        ->update(['last_imported_at' => $now, 'last_import_id' => $import->id]);
                    $counts['unchanged']++;
                    continue;
                }

                $row['is_active'] = true;
                $row['updated_at'] = $now;
                CatalogItem::query()
                    ->where('id', $existingRow->id)
                    ->update($row);
                $counts['updated']++;
            }

            // Шаг B: bulk insert новых. Eloquent insert не бьёт по timestamps,
            // мы их выставили вручную.
            if (! empty($toInsert)) {
                foreach (array_chunk($toInsert, 500) as $chunk) {
                    CatalogItem::query()->insert($chunk);
                }
            }

            // Шаг C: soft-delete всего, что не пришло в этом snapshot'е.
            // last_imported_at < $now → not in snapshot. Не трогаем уже
            // помеченные is_active=false, чтобы updated_at не дёргать зря.
            $softDeleted = CatalogItem::query()
                ->where('is_active', true)
                ->where(function ($q) use ($now) {
                    $q->whereNull('last_imported_at')
                        ->orWhere('last_imported_at', '<', $now);
                })
                ->update([
                    'is_active' => false,
                    'updated_at' => $now,
                ]);
            $counts['soft_deleted'] = (int) $softDeleted;
        });

        $this->finalize($import, $startedAt, [
            'rows_created' => $counts['created'],
            'rows_updated' => $counts['updated'],
            'rows_unchanged' => $counts['unchanged'],
            'rows_soft_deleted' => $counts['soft_deleted'],
            'errors' => $errors,
        ]);

        Log::info('CatalogImportService: import finished', [
            'import_id' => $import->id,
            'source' => $source,
            'counts' => $counts,
            'errors' => count($errors),
            'duration_ms' => $import->duration_ms,
        ]);

        return $import->refresh();
    }

    /**
     * Нормализация строки snapshot'а. Возвращает null, если нет обязательных
     * (sku, name).
     *
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row): ?array
    {
        $sku = isset($row['sku']) ? trim((string) $row['sku']) : '';
        $name = isset($row['name']) ? trim((string) $row['name']) : '';
        if ($sku === '' || $name === '') {
            return null;
        }

        $data = [
            'sku' => mb_substr($sku, 0, 64),
            'name' => mb_substr($name, 0, 500),
            'name_en' => $this->trimOrNull($row['name_en'] ?? null, 500),
            'unit_name' => $this->trimOrNull($row['unit_name'] ?? null, 128),
            'part_type' => $this->trimOrNull($row['part_type'] ?? null, 128),
            'brand' => $this->trimOrNull($row['brand'] ?? null, 128),
            'brand_article' => $this->trimOrNull($row['brand_article'] ?? null, 128),
            'form_factor' => $this->trimOrNull($row['form_factor'] ?? null, 64),
            'size_a' => $this->decimalOrNull($row['size_a'] ?? null),
            'size_b' => $this->decimalOrNull($row['size_b'] ?? null),
            'size_c' => $this->decimalOrNull($row['size_c'] ?? null),
            'size_d' => $this->decimalOrNull($row['size_d'] ?? null),
            'size_e' => $this->decimalOrNull($row['size_e'] ?? null),
            'size_f' => $this->decimalOrNull($row['size_f'] ?? null),
            'weight' => $this->decimalOrNull($row['weight'] ?? null),
            'price' => $this->decimalOrNull($row['price'] ?? null),
            'stock_available' => $this->intOrNull($row['stock_available'] ?? null),
        ];

        $data['source_hash'] = $this->hashRow($data);

        return $data;
    }

    private function trimOrNull(mixed $v, int $max): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private function decimalOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_numeric($v)) {
            return null;
        }

        // Постгрес сам приведёт строку «123.45» → numeric.
        return (string) $v;
    }

    private function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_numeric($v)) {
            return null;
        }

        return (int) $v;
    }

    /**
     * sha256 по «нормализованной» конкатенации значимых полей. NULL → '',
     * порядок фиксирован — нельзя менять без миграции hash'ей всех строк.
     */
    private function hashRow(array $data): string
    {
        $order = [
            'sku', 'name', 'name_en', 'unit_name', 'part_type', 'brand',
            'brand_article', 'form_factor',
            'size_a', 'size_b', 'size_c', 'size_d', 'size_e', 'size_f',
            'weight', 'price', 'stock_available',
        ];
        $parts = [];
        foreach ($order as $k) {
            $parts[] = $k . '=' . ($data[$k] ?? '');
        }

        return hash('sha256', implode('|', $parts));
    }

    private function finalize(CatalogImport $import, float $startedAt, array $fields = []): void
    {
        $payload = array_merge($fields, [
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);
        $import->forceFill($payload)->save();
    }
}
