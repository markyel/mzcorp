<?php

namespace App\Console\Commands\Catalog;

use App\Jobs\Catalog\ResolvePendingFromCatalogJob;
use App\Services\Catalog\CatalogImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2: одноразовая (или ad-hoc) загрузка каталога из локального файла
 * (CSV / JSON), без HTTP-эндпоинта. Используется для первичной выгрузки
 * и для разовых ручных обновлений до того, как наладят регулярный sync.
 *
 * CSV-режим заточен под экспорт из MS Access UI на русской Windows:
 *   - автодетект разделителя (`;`, `\t`, `,`),
 *   - дефолт-encoding cp1251 (переопределяется --encoding=utf-8),
 *   - русские числа «12,5» → «12.5» для numeric-полей.
 *
 * Заголовки CSV ожидаются ровно как в MDB-таблице (см. ниже маппинг).
 *
 * Использование:
 *   php artisan catalog:import /tmp/catalog.csv               # dry-run
 *   php artisan catalog:import /tmp/catalog.csv --apply       # с записью в БД
 *   php artisan catalog:import /tmp/catalog.csv --apply --allow-small
 *   php artisan catalog:import /tmp/catalog.json --apply
 */
class CatalogImportCommand extends Command
{
    protected $signature = 'catalog:import
        {file : Путь к CSV или JSON snapshot}
        {--apply : Применить (без флага — dry-run, только распарсить и показать)}
        {--allow-small : Обойти CATALOG_IMPORT_MIN_FULL_ROWS guard (для первой выгрузки)}
        {--encoding=cp1251 : Кодировка CSV (cp1251|utf-8)}
        {--delimiter= : Разделитель CSV; если не указан — автодетект}
        {--source=cli : Метка источника для audit-записи в catalog_imports}';

    protected $description = 'Phase 2: загрузить snapshot каталога из локального CSV/JSON.';

    /**
     * Маппинг русских заголовков MDB → канонические ключи payload'а.
     *
     * Ключи с суффиксом `_raw` несут сырую строку из CSV и парсятся уже в
     * CatalogImportService (мультиполя `;`-split, размеры «A=240;B=55;C=18»,
     * «Да»/«Нет»). Это держит CSV-парсер дурацки-простым (cell-in/cell-out)
     * и переносит всю доменную нормализацию в одно место.
     *
     * Колонки «Ссылка» и «CRC» из MDB сознательно НЕ маппим — нет потребителя.
     *
     * @var array<string, string>
     */
    private const HEADER_MAPPING = [
        'Артикул' => 'sku',
        'Наименование' => 'name',
        'НаименованиеENG' => 'name_en',
        'Бренды' => 'brands_raw',
        'Артикулы' => 'articles_raw',
        'Узлы' => 'units_raw',
        'Размещение' => 'placement',
        'ТипЗапчасти' => 'part_type',
        'ФормФактор' => 'form_factor',
        'Размеры' => 'sizes_raw',
        'Вес' => 'weight',
        'Цена' => 'price',
        'ЦенаМин' => 'price_min',
        'ЦенаЗакупки' => 'purchase_price',
        'Актуальность' => 'is_price_actual_raw',
        'СвободныйОстаток' => 'stock_available',
        'СвободноВПути' => 'stock_in_transit_raw',
        'СрокПоставки' => 'lead_time_days',
        'Фото' => 'photo_url',
        'Комментарий' => 'comment',
        'Описание' => 'description',
    ];

    /** @var array<string> Скалярные numeric поля: русская запятая (12,5) → точка, удаление NBSP/пробелов */
    private const NUMERIC_KEYS = [
        'weight', 'price', 'price_min', 'purchase_price',
    ];

    /** @var array<string> Целочисленные поля: только удаление пробелов/NBSP */
    private const INTEGER_KEYS = [
        'stock_available', 'lead_time_days',
    ];

    public function handle(CatalogImportService $service): int
    {
        $path = (string) $this->argument('file');
        if (! file_exists($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        try {
            $rows = match ($ext) {
                'csv', 'txt', 'tsv' => $this->readCsv($path),
                'json' => $this->readJson($path),
                default => throw new \RuntimeException("Неподдерживаемый формат: .{$ext}"),
            };
        } catch (\Throwable $e) {
            $this->error("Не удалось распарсить файл: {$e->getMessage()}");

            return self::FAILURE;
        }

        $count = count($rows);
        $this->info("Прочитано строк: {$count}");
        if ($count === 0) {
            $this->error('Нет валидных строк (нужны sku + name).');

            return self::FAILURE;
        }

        // Превью первой строки для глазной проверки маппинга.
        $this->line('Пример первой строки:');
        $this->line('  ' . json_encode($rows[0], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Dry-run. Чтобы применить — запусти повторно с --apply.');

            return self::SUCCESS;
        }

        // Min_full_rows guard. С --allow-small обходим (нужно для первичной
        // выгрузки, когда оператор ещё не знает реальный размер каталога).
        $minRows = (int) config('services.catalog_import.min_full_rows', 1);
        if (! $this->option('allow-small') && $count < $minRows) {
            $this->error(
                "rows={$count} < CATALOG_IMPORT_MIN_FULL_ROWS={$minRows}. "
                . 'Если уверен — добавь --allow-small. Сейчас отказ во избежание soft-delete всего каталога.'
            );

            return self::FAILURE;
        }

        $payload = [
            'mode' => 'full',
            'source' => (string) $this->option('source'),
            'items' => $rows,
        ];

        $this->info('Запускаю CatalogImportService...');
        try {
            $import = $service->import($payload, 'cli');
        } catch (\Throwable $e) {
            $this->error("Импорт упал: {$e->getMessage()}");
            Log::error('catalog:import command failed', [
                'error' => $e->getMessage(),
                'file' => $path,
            ]);

            return self::FAILURE;
        }

        $this->table(
            ['metric', 'value'],
            [
                ['import_id', (string) $import->id],
                ['rows_total', (string) $import->rows_total],
                ['rows_created', (string) $import->rows_created],
                ['rows_updated', (string) $import->rows_updated],
                ['rows_unchanged', (string) $import->rows_unchanged],
                ['rows_soft_deleted', (string) $import->rows_soft_deleted],
                ['duration_ms', (string) $import->duration_ms],
                ['errors', (string) count($import->errors ?? [])],
            ],
        );

        if (! empty($import->errors)) {
            $this->warn('Ошибки/предупреждения:');
            foreach (array_slice($import->errors, 0, 20) as $err) {
                $this->line('  ' . json_encode($err, JSON_UNESCAPED_UNICODE));
            }
            if (count($import->errors) > 20) {
                $this->line('  ... ещё ' . (count($import->errors) - 20));
            }
        }

        $touched = $import->rows_created + $import->rows_updated + $import->rows_soft_deleted;
        if ($touched > 0) {
            ResolvePendingFromCatalogJob::dispatch();
            $this->info('ResolvePendingFromCatalogJob поставлен в очередь — после прогона позиции с internal_catalog_pending должны переключиться в sufficient.');
        }

        return self::SUCCESS;
    }

    /**
     * Чтение CSV с автодетектом разделителя + конвертацией encoding.
     *
     * @return list<array<string, mixed>>
     */
    private function readCsv(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Не удалось прочитать {$path}");
        }

        $encoding = strtolower((string) $this->option('encoding')) ?: 'cp1251';
        if ($encoding !== 'utf-8' && $encoding !== 'utf8') {
            $converted = mb_convert_encoding($content, 'UTF-8', $encoding);
            if ($converted === false) {
                throw new \RuntimeException("Не удалось конвертировать encoding {$encoding} → UTF-8");
            }
            $content = $converted;
        }

        // Срезаем BOM, если есть.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $delimiter = (string) ($this->option('delimiter') ?? '');
        if ($delimiter === '') {
            $delimiter = $this->detectDelimiter($content);
            $this->line('Автодетект разделителя: ' . json_encode($delimiter));
        }

        // Парсим через временный stream чтобы str_getcsv корректно обрабатывал
        // кавычки и многострочные значения.
        $tmp = fopen('php://memory', 'r+');
        fwrite($tmp, $content);
        rewind($tmp);

        $headers = fgetcsv($tmp, 0, $delimiter, '"', '\\');
        if ($headers === false || $headers === null) {
            fclose($tmp);
            throw new \RuntimeException('Пустой CSV / нет заголовков.');
        }
        $headers = array_map(fn ($h) => trim((string) $h, " \t\r\n\""), $headers);

        // Маппинг index → API-ключ. Колонки, которых нет в HEADER_MAPPING, игнорируем.
        $colMap = [];
        foreach ($headers as $i => $h) {
            if (isset(self::HEADER_MAPPING[$h])) {
                $colMap[$i] = self::HEADER_MAPPING[$h];
            }
        }
        if (! in_array('sku', $colMap, true) || ! in_array('name', $colMap, true)) {
            fclose($tmp);
            throw new \RuntimeException('CSV не содержит обязательных колонок «Артикул» и «Наименование».');
        }

        $rows = [];
        while (($cells = fgetcsv($tmp, 0, $delimiter, '"', '\\')) !== false) {
            if ($cells === [null]) {
                continue; // пустая строка
            }
            $row = [];
            foreach ($colMap as $i => $key) {
                $val = $cells[$i] ?? null;
                if ($val === null) {
                    continue;
                }
                $val = trim((string) $val);
                if ($val === '') {
                    continue;
                }
                if (in_array($key, self::NUMERIC_KEYS, true)) {
                    // Русская десятичная запятая → точка.
                    $val = str_replace([',', "\xC2\xA0"], ['.', ''], $val);
                    // Уберём пробелы-разделители тысяч.
                    $val = preg_replace('/\s+/', '', $val);
                } elseif (in_array($key, self::INTEGER_KEYS, true)) {
                    $val = preg_replace('/\s+/', '', $val);
                }
                // Для `_raw` полей (multi-value/sizes) и текстовых — ничего
                // не трогаем, пускай сервис нормализует.
                $row[$key] = $val;
            }
            if (! empty($row['sku']) && ! empty($row['name'])) {
                $rows[] = $row;
            }
        }
        fclose($tmp);

        return $rows;
    }

    /**
     * Чтение JSON-snapshot в формате `{mode:'full', items:[...]}`
     * или просто массива объектов.
     *
     * @return list<array<string, mixed>>
     */
    private function readJson(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Не удалось прочитать {$path}");
        }
        $parsed = json_decode($content, true);
        if (! is_array($parsed)) {
            throw new \RuntimeException('JSON: ожидаем объект {items:[...]} или массив.');
        }
        $items = isset($parsed['items']) && is_array($parsed['items']) ? $parsed['items'] : $parsed;
        $rows = [];
        foreach ($items as $i) {
            if (is_array($i) && ! empty($i['sku']) && ! empty($i['name'])) {
                $rows[] = $i;
            }
        }

        return $rows;
    }

    /**
     * Автодетект разделителя по первой непустой строке. Голосование
     * между `;`, `\t`, `,` — побеждает максимальное количество вхождений.
     */
    private function detectDelimiter(string $content): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $firstLine = '';
        foreach ($lines as $l) {
            if (trim($l) !== '') {
                $firstLine = $l;
                break;
            }
        }
        $candidates = [';' => 0, "\t" => 0, ',' => 0];
        foreach ($candidates as $d => $_) {
            $candidates[$d] = substr_count($firstLine, $d);
        }
        arsort($candidates);
        $top = array_key_first($candidates);

        return $candidates[$top] > 0 ? $top : ';';
    }
}
