<?php

namespace App\Console\Commands;

use App\Services\Marketing\PressOutletImporter;
use Illuminate\Console\Command;

/**
 * Загрузить базу СМИ для пресс-релизов из xlsx (лист «СМИ»).
 */
class PressImportOutletsCommand extends Command
{
    protected $signature = 'press:import-outlets {path : путь к xlsx} {--dry-run : только посчитать}';

    protected $description = 'Import press outlets (media list for press releases) from an xlsx file';

    public function handle(PressOutletImporter $importer): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error('Файл не найден: '.$path);

            return self::FAILURE;
        }

        try {
            $res = $importer->import($path, (bool) $this->option('dry-run'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '')
            .'Новых площадок: '.$res['created'].', обновлено: '.$res['updated'].'.');
        foreach ($res['skipped'] as $line) {
            $this->warn('Пропущено — '.$line);
        }

        return self::SUCCESS;
    }
}
