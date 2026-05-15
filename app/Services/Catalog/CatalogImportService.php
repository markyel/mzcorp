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
 * Контракт входа (см. `POST /api/catalog/import` и `php artisan catalog:import`):
 *   {
 *     "mode":   "full",
 *     "source": "office-pc-01",          // опц., source-tag
 *     "items":  [
 *       {
 *         "sku":                 "M02016",                 // обяз.
 *         "name":                "...",                    // обяз.
 *         "name_en":             "...",                    // опц.
 *
 *         // Мульти-поля. Принимаются ИЛИ как сырая строка `*_raw`
 *         // («;»-список из MDB / CSV), ИЛИ как готовый массив для JSON-клиентов.
 *         // brand/brand_article в payload игнорируются — primary OEM
 *         // вычисляем из brands/articles (см. pickPrimaryOem).
 *         "brands_raw":          "ZIEHL-ABEGG;KLEEMANN;Мой ЗиП",
 *         "articles_raw":        ";6F31-04-12018;M15862",
 *         "units_raw":           "Главный привод, лебёдка лифта…",
 *         // или
 *         "brands":              ["ZIEHL-ABEGG", "KLEEMANN", "Мой ЗиП"],
 *         "articles":            [null, "6F31-04-12018", "M15862"],
 *         "units":               ["..."],
 *
 *         "placement":           "Лифт",
 *         "part_type":           "...",
 *         "form_factor":         "...",
 *
 *         // Размеры. Сырой строкой «A=240;B=55;C=18» (-' = пропуск),
 *         // ИЛИ старые size_a..size_f скаляры (для обратной совместимости).
 *         "sizes_raw":           "A=240;B=55;C=18",
 *         "weight":              0.350,
 *
 *         "price":               1234.50,
 *         "price_min":           1099.00,
 *
 *         // «Актуальность» — валидна ли цена для трансляции клиенту.
 *         // Принимаем «Да»/«Нет»/bool/0/1. Дефолт true, если поле отсутствует.
 *         "is_price_actual_raw": "Да",
 *         // или
 *         "is_price_actual":     true,
 *
 *         "stock_available":     5,
 *         "lead_time_days":      14,
 *         "photo_url":           "https://...",
 *         "description":         "...",
 *         "comment":             "..."
 *       },
 *       ...
 *     ]
 *   }
 *
 * Логика:
 *  1. Валидируем mode = full (delta пока не поддержан).
 *  2. По каждой row: нормализуем (split multi-полей, parse размеров,
 *     primary-OEM-выбор) + считаем source_hash.
 *  3. Upsert по sku: existing — UPDATE, new — bulk INSERT чанками по 500.
 *  4. После апсёрта soft-delete: всё, чего нет в snapshot'е, → is_active=false.
 *  5. Пишем CatalogImport-запись (аудит) с counter'ами и errors[].
 *
 * Идемпотентность: при повторной выгрузке того же снапшота все строки
 * выявятся как unchanged (source_hash совпадёт), 0 updates, 0 created,
 * 0 soft_deleted — никаких side-effects.
 *
 * NB: после расширения схемы (миграция 2026_05_15_130000) первый импорт
 * с офисной машины пометит все строки как rows_updated, потому что
 * hashRow расширен новыми полями. Это ожидаемо.
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

        // --- Мульти-поля: brands/articles/units. Принимаем массив (JSON)
        //     или сырую `;`-строку (`*_raw`). Brands и articles ВЫРОВНЕНЫ
        //     1:1 по индексу — допустимы внутренние `null` слоты.
        $brands = $this->coerceMultiList($row['brands'] ?? null, $row['brands_raw'] ?? null);
        $articles = $this->coerceMultiList($row['articles'] ?? null, $row['articles_raw'] ?? null);
        $units = $this->coerceMultiList($row['units'] ?? null, $row['units_raw'] ?? $row['unit_name'] ?? null);

        // Выровнять длины brands/articles для 1:1 пар (пустые слоты — null).
        $pairLen = max(count($brands), count($articles));
        while (count($brands) < $pairLen) {
            $brands[] = null;
        }
        while (count($articles) < $pairLen) {
            $articles[] = null;
        }

        // --- Скалярные brand/brand_article: primary OEM из мульти-списков.
        [$primaryBrand, $primaryArticle] = $this->pickPrimaryOem($brands, $articles);

        // --- Скалярный unit_name: первый non-empty из units.
        $primaryUnit = null;
        foreach ($units as $u) {
            if ($u !== null && $u !== '') {
                $primaryUnit = $u;
                break;
            }
        }

        // --- Размеры: парсим «A=240;B=55;C=18», либо забираем legacy size_a..f.
        $sizes = $this->resolveSizes($row);

        // --- Актуальность цены: дефолт true, когда поле отсутствует.
        $rawActual = $row['is_price_actual_raw'] ?? $row['is_price_actual'] ?? null;
        $isPriceActual = $rawActual === null ? true : $this->yesNoBool($rawActual);

        // jsonb-колонки. Eloquent cast применяется только в Model::save(),
        // а мы делаем raw Query Builder insert/update — encoding делаем здесь.
        $data = [
            'sku' => mb_substr($sku, 0, 64),
            'name' => mb_substr($name, 0, 500),
            'name_en' => $this->trimOrNull($row['name_en'] ?? null, 500),
            'unit_name' => $primaryUnit !== null ? mb_substr($primaryUnit, 0, 128) : null,
            'units' => $this->jsonOrNull($units),
            'placement' => $this->trimOrNull($row['placement'] ?? null, 64),
            'part_type' => $this->trimOrNull($row['part_type'] ?? null, 128),
            'brand' => $primaryBrand !== null ? mb_substr($primaryBrand, 0, 128) : null,
            'brand_article' => $primaryArticle !== null ? mb_substr($primaryArticle, 0, 128) : null,
            // Use-case B: префакомпилированная форма артикула для article-match
            // против `parsed_article` позиций. См. CatalogResolutionService.
            'brand_article_normalized' => $this->normalizeArticle($primaryArticle),
            'brands' => $this->jsonOrNull($brands),
            'articles' => $this->jsonOrNull($articles),
            'form_factor' => $this->trimOrNull($row['form_factor'] ?? null, 64),
            'size_a' => $this->decimalOrNull($sizes['a'] ?? null),
            'size_b' => $this->decimalOrNull($sizes['b'] ?? null),
            'size_c' => $this->decimalOrNull($sizes['c'] ?? null),
            'size_d' => $this->decimalOrNull($sizes['d'] ?? null),
            'size_e' => $this->decimalOrNull($sizes['e'] ?? null),
            'size_f' => $this->decimalOrNull($sizes['f'] ?? null),
            'weight' => $this->decimalOrNull($row['weight'] ?? null),
            'price' => $this->decimalOrNull($row['price'] ?? null),
            'price_min' => $this->decimalOrNull($row['price_min'] ?? null),
            'is_price_actual' => $isPriceActual,
            'stock_available' => $this->intOrNull($row['stock_available'] ?? null),
            'lead_time_days' => $this->intOrNull($row['lead_time_days'] ?? null),
            'photo_url' => $this->trimOrNull($row['photo_url'] ?? null, 500),
            'description' => $this->textOrNull($row['description'] ?? null),
            'comment' => $this->textOrNull($row['comment'] ?? null),
        ];

        $data['source_hash'] = $this->hashRow($data);

        return $data;
    }

    /**
     * Свести multi-поле к массиву строк/null. Принимает либо массив (JSON-клиент),
     * либо сырую `;`-строку (CSV из MDB). Trailing `null`-слоты обрезаются
     * — внутренние сохраняются для 1:1 выравнивания с парным списком.
     *
     * @return list<?string>
     */
    private function coerceMultiList(mixed $array, mixed $raw): array
    {
        if (is_array($array)) {
            $items = $array;
        } elseif ($raw === null) {
            return [];
        } else {
            $rawStr = trim((string) $raw);
            if ($rawStr === '') {
                return [];
            }
            $items = explode(';', $rawStr);
        }

        $out = [];
        foreach ($items as $v) {
            if ($v === null) {
                $out[] = null;
                continue;
            }
            $s = trim((string) $v);
            $out[] = $s === '' ? null : mb_substr($s, 0, 128);
        }
        while (! empty($out) && end($out) === null) {
            array_pop($out);
        }

        return $out;
    }

    /**
     * Выбрать primary-OEM-пару (brand, brand_article) из 1:1-выровненных
     * списков. Логика:
     *  1) Первая пара, где brand не «Мой ЗиП» И есть непустой article.
     *  2) Первая пара, где brand не «Мой ЗиП» (даже если article пуст).
     *  3) Любая первая пара с непустым brand (включая «Мой ЗиП»).
     *
     * «Мой ЗиП» — это компанейский тег, в article-слоте у него обычно
     * лежит наш же M-SKU. Для матчинга по артикулу нам нужен OEM (Otis/
     * Kone/ZIEHL-ABEGG), потому пропускаем его в первую очередь.
     *
     * @param  list<?string> $brands
     * @param  list<?string> $articles
     * @return array{0: ?string, 1: ?string}
     */
    private function pickPrimaryOem(array $brands, array $articles): array
    {
        $count = max(count($brands), count($articles));

        $isSelfTag = static fn (?string $b): bool => $b !== null
            && mb_stripos($b, 'Мой ЗиП') !== false;

        // Pass 1: OEM brand с непустым article.
        for ($i = 0; $i < $count; $i++) {
            $b = $brands[$i] ?? null;
            $a = $articles[$i] ?? null;
            if ($b !== null && $b !== '' && ! $isSelfTag($b)
                && $a !== null && $a !== '') {
                return [$b, $a];
            }
        }
        // Pass 2: любой не-«Мой ЗиП» brand (даже с пустым article).
        for ($i = 0; $i < $count; $i++) {
            $b = $brands[$i] ?? null;
            if ($b !== null && $b !== '' && ! $isSelfTag($b)) {
                return [$b, $articles[$i] ?? null];
            }
        }
        // Pass 3: fallback — первый непустой brand (даже «Мой ЗиП»).
        for ($i = 0; $i < $count; $i++) {
            $b = $brands[$i] ?? null;
            if ($b !== null && $b !== '') {
                return [$b, $articles[$i] ?? null];
            }
        }

        return [null, null];
    }

    /**
     * Распарсить «Размеры» вида «A=240;B=55;C=18» в массив a..f → строковое
     * представление числа. `-` или пустое значение → пропуск (null).
     *
     * Fallback: если sizes_raw не передан, читаем legacy скаляры size_a..size_f.
     *
     * @return array<string, ?string>
     */
    private function resolveSizes(array $row): array
    {
        if (isset($row['sizes_raw']) && trim((string) $row['sizes_raw']) !== '') {
            $out = [];
            foreach (explode(';', (string) $row['sizes_raw']) as $part) {
                $part = trim($part);
                if (! preg_match('/^([A-Fa-f])\s*=\s*(.*)$/u', $part, $m)) {
                    continue;
                }
                $key = strtolower($m[1]);
                $val = trim($m[2]);
                if ($val === '' || $val === '-') {
                    continue;
                }
                // Русская десятичная запятая → точка, удалить NBSP/пробелы.
                $val = str_replace([',', "\xC2\xA0"], ['.', ''], $val);
                $val = preg_replace('/\s+/', '', $val);
                $out[$key] = $val;
            }

            return $out;
        }

        // Legacy: отдельные ключи size_a..size_f.
        $out = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $k) {
            if (isset($row["size_{$k}"])) {
                $out[$k] = $row["size_{$k}"];
            }
        }

        return $out;
    }

    /**
     * «Да»/«Нет»/«Yes»/«No»/«1»/«0»/bool → bool.
     */
    private function yesNoBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if ($v === null) {
            return false;
        }
        $s = mb_strtolower(trim((string) $v));

        return in_array($s, ['да', 'yes', 'y', 'true', '1', 'актуально', 'актуальна'], true);
    }

    /**
     * Сериализовать массив в JSON для прямого raw INSERT/UPDATE в jsonb-колонку
     * (Eloquent-cast 'array' срабатывает только в Model::save()).
     * Пустые/null-only → NULL.
     *
     * @param  array<int, mixed>|null $value
     */
    private function jsonOrNull(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $hasMeaning = false;
        foreach ($value as $v) {
            if ($v !== null && $v !== '') {
                $hasMeaning = true;
                break;
            }
        }
        if (! $hasMeaning) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * trim + null-empty, без обрезания длины (для TEXT-полей description/comment).
     */
    private function textOrNull(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    /**
     * Нормализация артикула: uppercase + удаление [\s\-_./]. Совпадает с
     * `RequestItemParsingService::normalizeArticle`, чтобы catalog vs parsed
     * сравнения работали симметрично. Использовать ТУ ЖЕ маску, иначе ловим
     * false-negative на разных вариантах написания у клиента vs в каталоге.
     */
    public static function normalizeArticle(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        // Cyrillic → latin lookalike fold ДО uppercase — иначе клиентский
        // «М14224» (кириллическая М, U+041C) не сматчится с каталожным
        // «M14224» (latin M, U+004D).
        $s = self::cyrillicLookalikeFold($s);
        $s = preg_replace('/[\s\-_.\/]/', '', mb_strtoupper($s));

        return mb_substr((string) $s, 0, 128) ?: null;
    }

    /**
     * Заменить визуально идентичные кириллические буквы латиницей.
     * Применяется к артикулам и M-SKU detector'у — клиенты часто
     * случайно набирают «М» вместо «M», «А» вместо «A» и т.п.
     * (особенно при copy-paste из 1C / автозамене в Word).
     *
     * Список — только uppercase/lowercase пары, которые **визуально
     * неотличимы** в большинстве шрифтов: А/A В/B Е/E К/K М/M Н/H О/O
     * Р/P С/C Т/T Х/X (uppercase) + а/a е/e к/k м/m о/o р/p с/c х/x.
     */
    public static function cyrillicLookalikeFold(string $value): string
    {
        return strtr($value, [
            'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M',
            'Н' => 'H', 'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T',
            'Х' => 'X',
            'а' => 'a', 'е' => 'e', 'к' => 'k', 'м' => 'm', 'о' => 'o',
            'р' => 'p', 'с' => 'c', 'х' => 'x',
        ]);
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
     * bool → '0'/'1', JSON-строки — как есть. Порядок фиксирован — нельзя
     * менять без полной перепрогонки snapshot'а (что и так пройдёт само на
     * следующем импорте — все строки попадут в rows_updated).
     */
    private function hashRow(array $data): string
    {
        $order = [
            'sku', 'name', 'name_en', 'unit_name', 'units', 'placement',
            'part_type', 'brand', 'brand_article',
            'brands', 'articles', 'form_factor',
            'size_a', 'size_b', 'size_c', 'size_d', 'size_e', 'size_f',
            'weight', 'price', 'price_min', 'is_price_actual',
            'stock_available', 'lead_time_days',
            'photo_url', 'description', 'comment',
        ];
        $parts = [];
        foreach ($order as $k) {
            $v = $data[$k] ?? null;
            if ($v === null) {
                $v = '';
            } elseif (is_bool($v)) {
                $v = $v ? '1' : '0';
            }
            $parts[] = $k . '=' . $v;
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
