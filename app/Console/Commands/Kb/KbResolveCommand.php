<?php

namespace App\Console\Commands\Kb;

use App\Jobs\Kb\ResolveKbJob;
use App\Models\Request as RequestModel;
use App\Services\Kb\QualityAssessmentService;
use App\Services\Kb\RequestContextAnalysisService;
use Illuminate\Console\Command;
use Throwable;

/**
 * KB-резолв одной заявки или batch'а.
 *
 *   php artisan kb:resolve {request_id}            — single, синхронно (для дебага)
 *   php artisan kb:resolve --all [--limit=10] [--from-id=N] [--apply] [--force]
 *
 * Без --apply — dry-run (только перечисляет id заявок).
 * --force — игнорирует уже резолвленные позиции (status != not_assessed).
 */
class KbResolveCommand extends Command
{
    protected $signature = 'kb:resolve
        {request? : Single request id (если не задан, нужен --all)}
        {--all : Обработать все Request в системе}
        {--limit=0 : Максимум заявок для обработки (0 = без ограничения)}
        {--from-id=0 : Начать с указанного Request.id}
        {--apply : Реально запустить (без флага — dry-run)}
        {--force : Перезапустить даже если quality_assessment_status уже не not_assessed}
        {--sync : Запустить синхронно (без queue) для дебага}';

    protected $description = 'KB-резолв заявок: RequestContext + per-item QualityAssessment';

    public function handle(
        RequestContextAnalysisService $contextAnalyzer,
        QualityAssessmentService $assessor,
    ): int {
        $singleId = $this->argument('request');
        $all = (bool) $this->option('all');

        if (! $singleId && ! $all) {
            $this->error('Укажите request id или --all.');

            return self::INVALID;
        }

        if ($singleId) {
            return $this->processOne((int) $singleId, $contextAnalyzer, $assessor);
        }

        return $this->processBatch($contextAnalyzer, $assessor);
    }

    private function processOne(
        int $id,
        RequestContextAnalysisService $contextAnalyzer,
        QualityAssessmentService $assessor,
    ): int {
        $request = RequestModel::with('items')->find($id);
        if (! $request) {
            $this->error("Request id={$id} not found.");

            return self::FAILURE;
        }

        $this->line("Request #{$request->internal_code} — {$request->items->count()} items");

        if (! $this->option('apply')) {
            $this->warn('Dry-run (без --apply). Ничего не запущено.');

            return self::SUCCESS;
        }

        try {
            $contextAnalyzer->analyze($id);
            $this->info('  context analyzed.');
        } catch (Throwable $e) {
            $this->warn('  context failed: ' . $e->getMessage());
        }

        foreach ($request->items as $item) {
            try {
                $assessor->assessItem($item->id);
                $item->refresh();
                $this->line(sprintf(
                    '  item #%d: status=%s, brand=%s, category=%s',
                    $item->id,
                    $item->quality_assessment_status,
                    $item->manufacturer_brand_id ?? '—',
                    $item->identification_category_id ?? '—',
                ));
            } catch (Throwable $e) {
                $this->error("  item #{$item->id} failed: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function processBatch(
        RequestContextAnalysisService $contextAnalyzer,
        QualityAssessmentService $assessor,
    ): int {
        $query = RequestModel::query();

        if ($fromId = (int) $this->option('from-id')) {
            $query->where('id', '>=', $fromId);
        }

        if (! $this->option('force')) {
            // Берём только Request, у которых ХОТЯ БЫ ОДИН item с
            // status=not_assessed (не резолвленный).
            $query->whereHas('items', function ($q) {
                $q->where('quality_assessment_status', 'not_assessed');
            });
        }

        $query->orderBy('id');

        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }

        $requests = $query->get(['id', 'internal_code']);
        $count = $requests->count();

        $this->line("Найдено заявок: {$count}");

        if (! $this->option('apply')) {
            foreach ($requests->take(20) as $r) {
                $this->line("  - #{$r->internal_code} (id={$r->id})");
            }
            if ($count > 20) {
                $this->line('  …');
            }
            $this->warn('Dry-run (без --apply). Ничего не запущено.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach ($requests as $r) {
            $job = new ResolveKbJob($r->id);
            if ($sync) {
                dispatch_sync($job);
            } else {
                dispatch($job);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info($sync ? "Обработано {$count} заявок." : "Запланировано {$count} job'ов в очередь.");

        return self::SUCCESS;
    }
}
