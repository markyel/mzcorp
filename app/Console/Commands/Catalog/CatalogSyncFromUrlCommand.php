<?php

namespace App\Console\Commands\Catalog;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Регулярный pull свежего MDB-снапшота каталога с публичного URL и
 * автоматический catalog:import.
 *
 * Pipeline:
 *   1. HEAD-precheck → Last-Modified / Content-Length сравниваем с метой
 *      прошлого успешного pull'а (storage/app/catalog/.last_sync.json).
 *      Если ничего не поменялось — выход 0, ничего не делаем.
 *   2. GET → сохраняем .mdb в storage/app/catalog/imports/catalog-YYYYMMDD-HHMM.mdb.
 *   3. SHA-256 — если хэш совпал с прошлым, всё равно выходим (Last-Modified
 *      может врать у php-script-endpoint'ов типа /getxfile.php).
 *   4. mdb-export из mdbtools конвертирует целевую таблицу в UTF-8 CSV
 *      во временный файл.
 *   5. Artisan::call('catalog:import') с --apply --encoding=utf-8.
 *   6. Ротация снапшотов до keep_snapshots последних (default 7 = ~3.5 дня
 *      при графике 11/15/19/23/03/07).
 *
 * Расписание (routes/console.php): cron `0 3,7,11,15,19,23 * * *` —
 * 6 запусков в сутки, начиная с 11:00 МСК (по требованию заказчика).
 *
 * Флаги:
 *   --force        : игнорировать unchanged-check, форсить полный pull
 *                    + import (ad-hoc прогон вручную).
 *   --dry-run      : скачать но НЕ импортировать (для тестов pipeline'а).
 *   --skip-import  : скачать + конвертировать, но не дёргать catalog:import.
 *                    Полезно при первой настройке — проверить mdb-export
 *                    отдельно перед автоматизацией.
 *
 * Зависимости на проде:
 *   sudo apt install mdbtools
 *   (включает mdb-tables, mdb-export, mdb-schema)
 */
class CatalogSyncFromUrlCommand extends Command
{
    protected $signature = 'catalog:sync-from-url
        {--url= : Переопределить URL (default — services.catalog_sync.url)}
        {--table= : Имя таблицы в MDB (default — services.catalog_sync.table)}
        {--force : Игнорировать unchanged-check (форсить pull+import)}
        {--dry-run : Скачать но не импортировать}
        {--skip-import : Скачать + конвертировать в CSV, но не запускать catalog:import}';

    protected $description = 'Регулярный pull MDB-каталога с public URL + конвертация mdbtools + auto-import.';

    private const STATE_FILE = 'catalog/.last_sync.json';

    public function handle(): int
    {
        $url = (string) ($this->option('url') ?: config('services.catalog_sync.url'));
        if ($url === '') {
            $this->error('URL не задан: --url=... или CATALOG_SYNC_URL в .env');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $skipImport = (bool) $this->option('skip-import');

        $disk = Storage::disk('local');
        $importsDir = 'catalog/imports';
        $disk->makeDirectory($importsDir);

        $state = $this->loadState($disk);

        // Шаг 1: HEAD-precheck. Не блокирующий — если сервер не отдаёт
        // Last-Modified, переходим к GET и сравниваем хэш после загрузки.
        $headers = $this->fetchHeaders($url);
        $lastModified = $headers['last-modified'][0] ?? null;
        $contentLength = $headers['content-length'][0] ?? null;

        if (! $force
            && $lastModified !== null
            && ($state['last_modified'] ?? null) === $lastModified
        ) {
            $this->info('HEAD: Last-Modified не изменился (' . $lastModified . '), skip.');
            $this->updateState($disk, $state, ['last_check_at' => now()->toIso8601String()]);

            return self::SUCCESS;
        }

        // Шаг 2: GET → tmp-файл (atomic move на финале).
        $stamp = now()->format('Ymd-Hi');
        $snapshotRel = $importsDir . "/catalog-{$stamp}.mdb";
        $tmpRel = $importsDir . "/catalog-{$stamp}.mdb.tmp";
        $tmpAbs = $disk->path($tmpRel);

        $this->info("GET {$url} → {$tmpRel}");
        try {
            $response = Http::timeout(120)->withOptions(['sink' => $tmpAbs])->get($url);
        } catch (\Throwable $e) {
            $this->error('HTTP-ошибка: ' . $e->getMessage());
            $this->updateState($disk, $state, [
                'last_error' => $e->getMessage(),
                'last_error_at' => now()->toIso8601String(),
            ]);

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $msg = 'HTTP ' . $response->status();
            $this->error($msg);
            @unlink($tmpAbs);
            $this->updateState($disk, $state, [
                'last_error' => $msg,
                'last_error_at' => now()->toIso8601String(),
            ]);

            return self::FAILURE;
        }

        // Базовая sanity: MDB начинается с magic bytes "\x00\x01\x00\x00 Standard Jet DB"
        // или "Standard ACE DB" для .accdb. Если HTML/JSON приехал вместо MDB —
        // сразу видно по первым байтам.
        $head = @file_get_contents($tmpAbs, false, null, 0, 32);
        if ($head === false || ! $this->looksLikeMdb($head)) {
            @unlink($tmpAbs);
            $msg = 'Скачанный файл не похож на MDB (первые байты: ' . bin2hex(substr($head ?: '', 0, 16)) . ')';
            $this->error($msg);
            $this->updateState($disk, $state, [
                'last_error' => $msg,
                'last_error_at' => now()->toIso8601String(),
            ]);

            return self::FAILURE;
        }

        // Шаг 3: SHA-256. Если содержимое не изменилось — выкидываем tmp.
        $sha = hash_file('sha256', $tmpAbs);
        $size = filesize($tmpAbs);
        $this->info(sprintf('Скачано: %d bytes, sha256=%s', $size, substr($sha, 0, 12) . '…'));

        if (! $force && ($state['sha256'] ?? null) === $sha) {
            @unlink($tmpAbs);
            $this->info('SHA-256 совпал с прошлым — содержимое не менялось, skip import.');
            $this->updateState($disk, $state, [
                'last_check_at' => now()->toIso8601String(),
                'last_modified' => $lastModified,
            ]);

            return self::SUCCESS;
        }

        // Atomic rename tmp → snapshot.
        if (! @rename($tmpAbs, $disk->path($snapshotRel))) {
            $msg = 'rename failed: ' . $tmpAbs . ' → ' . $snapshotRel;
            $this->error($msg);
            @unlink($tmpAbs);
            $this->updateState($disk, $state, [
                'last_error' => $msg,
                'last_error_at' => now()->toIso8601String(),
            ]);

            return self::FAILURE;
        }

        $this->updateState($disk, $state, [
            'sha256' => $sha,
            'size' => $size,
            'last_modified' => $lastModified,
            'last_pull_at' => now()->toIso8601String(),
            'last_pull_path' => $snapshotRel,
            'last_check_at' => now()->toIso8601String(),
        ]);

        // Шаг 6: ротация старых снапшотов.
        $this->rotateSnapshots($disk, $importsDir);

        if ($dryRun || $skipImport) {
            $this->info($dryRun ? 'Dry-run: import пропущен.' : 'Skip-import: pipeline остановлен после save.');
            Log::info('catalog:sync-from-url pulled', [
                'snapshot' => $snapshotRel,
                'size' => $size,
                'sha' => $sha,
                'dry_run' => $dryRun,
                'skip_import' => $skipImport,
            ]);

            return self::SUCCESS;
        }

        // Шаг 4: mdb-export → CSV.
        $table = (string) ($this->option('table') ?: config('services.catalog_sync.table', ''));
        if ($table === '') {
            $this->error('Таблица в MDB не задана: --table=... или CATALOG_SYNC_TABLE в .env');

            return self::FAILURE;
        }

        $csvRel = $importsDir . "/catalog-{$stamp}.csv";
        $csvAbs = $disk->path($csvRel);
        $this->info("mdb-export → {$csvRel}");

        // ВАЖНО: без -Q. Флаг -Q в mdb-export означает «НЕ wrap text в кавычки»,
        // что ломает CSV-парсер при значениях с запятой/переносом строки
        // (одна позиция «съезжает» по колонкам, начинается мисаллайнмент).
        // Default mdb-export quote'ит текст стандартными double-quote'ами.
        $process = new Process(['mdb-export', '-d', ',', $disk->path($snapshotRel), $table]);
        $process->setTimeout(120);
        $fh = fopen($csvAbs, 'wb');
        if (! $fh) {
            $this->error('Не открыть CSV на запись: ' . $csvAbs);

            return self::FAILURE;
        }
        $process->run(function ($type, $buffer) use ($fh) {
            if ($type === Process::OUT) {
                fwrite($fh, $buffer);
            }
        });
        fclose($fh);

        if (! $process->isSuccessful()) {
            $err = trim($process->getErrorOutput());
            $this->error('mdb-export упал: ' . $err);
            $this->updateState($disk, $state, [
                'last_error' => 'mdb-export: ' . $err,
                'last_error_at' => now()->toIso8601String(),
            ]);

            return self::FAILURE;
        }

        $csvSize = filesize($csvAbs);
        $this->info(sprintf('CSV готов: %d bytes', $csvSize));

        // Шаг 5: catalog:import --apply --encoding=utf-8.
        $exitCode = Artisan::call('catalog:import', [
            'file' => $csvAbs,
            '--apply' => true,
            '--encoding' => 'utf-8',
            '--delimiter' => ',',
            '--source' => 'auto_sync',
        ], $this->output);

        if ($exitCode !== 0) {
            $this->error('catalog:import вернул код ' . $exitCode);
            $this->updateState($disk, $state, [
                'last_error' => 'catalog:import exit=' . $exitCode,
                'last_error_at' => now()->toIso8601String(),
            ]);

            return self::FAILURE;
        }

        $this->updateState($disk, $state, [
            'last_import_at' => now()->toIso8601String(),
            'last_import_path' => $csvRel,
            'last_error' => null,
            'last_error_at' => null,
        ]);

        $this->info('OK: import завершён.');
        Log::info('catalog:sync-from-url imported', [
            'snapshot' => $snapshotRel,
            'csv' => $csvRel,
            'size' => $size,
            'sha' => $sha,
        ]);

        return self::SUCCESS;
    }

    /**
     * Magic bytes для .mdb (Jet 4.0) и .accdb (ACE).
     */
    private function looksLikeMdb(string $head): bool
    {
        // Jet 4 MDB: первые 4 байта 00 01 00 00 + далее "Standard Jet DB"
        // ACE: "Standard ACE DB"
        return str_contains($head, 'Standard Jet DB')
            || str_contains($head, 'Standard ACE DB');
    }

    /**
     * @return array<string, list<string>>  lowercase header → values
     */
    private function fetchHeaders(string $url): array
    {
        try {
            $response = Http::timeout(15)->head($url);
            $out = [];
            foreach ($response->headers() as $k => $v) {
                $out[mb_strtolower($k)] = $v;
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadState($disk): array
    {
        if (! $disk->exists(self::STATE_FILE)) {
            return [];
        }
        $raw = $disk->get(self::STATE_FILE);
        $decoded = json_decode($raw ?: 'null', true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $patch
     */
    private function updateState($disk, array $state, array $patch): void
    {
        $merged = array_merge($state, $patch);
        $disk->put(self::STATE_FILE, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function rotateSnapshots($disk, string $dir): void
    {
        $keep = max(1, (int) config('services.catalog_sync.keep_snapshots', 7));
        $files = collect($disk->files($dir))
            ->filter(fn ($p) => str_ends_with($p, '.mdb') || str_ends_with($p, '.csv'))
            ->sort()
            ->values();

        // Каждый pull создаёт два файла (.mdb + .csv) одной серии. Считаем
        // по уникальным timestamp-префиксам и оставляем последние $keep серий.
        $stamps = $files
            ->map(fn ($p) => preg_match('/catalog-(\d{8}-\d{4})\./', basename($p), $m) ? $m[1] : null)
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($stamps->count() <= $keep) {
            return;
        }
        $toDrop = $stamps->slice(0, $stamps->count() - $keep);
        foreach ($files as $p) {
            foreach ($toDrop as $stamp) {
                if (str_contains($p, "catalog-{$stamp}.")) {
                    $disk->delete($p);
                    break;
                }
            }
        }
    }
}
