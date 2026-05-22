<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\Request;
use App\Models\RequestStateChange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `requests.peak_status` по истории `request_state_changes`.
 *
 * После добавления колонки peak_status (миграция 2026_05_28_120000) старые
 * заявки имеют peak_status=NULL. Эта команда пробегает state-changes для
 * каждой заявки, считает max(lifecycleOrder) по to_status и записывает.
 *
 * --dry-run — показать число затрагиваемых заявок без UPDATE.
 * Идемпотентно — повторный запуск перезаписывает уже backfill'ленные.
 */
class RequestsBackfillPeakStatusCommand extends Command
{
    protected $signature = 'requests:backfill-peak-status
        {--dry-run : Показать что будет сделано, без UPDATE.}';

    protected $description = 'Заполняет requests.peak_status из истории request_state_changes.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $totalRequests = Request::query()->count();
        $this->line("Всего заявок: {$totalRequests}. Mode: " . ($dryRun ? 'DRY-RUN' : 'APPLY'));
        $this->newLine();

        $stats = ['scanned' => 0, 'updated' => 0, 'skipped' => 0];

        Request::query()
            ->select(['id', 'status', 'peak_status'])
            ->orderBy('id')
            ->chunk(500, function ($chunk) use (&$stats, $dryRun) {
                foreach ($chunk as $req) {
                    $stats['scanned']++;

                    // Кандидаты: все to_status из истории + текущий status.
                    $historicalStatuses = RequestStateChange::query()
                        ->where('request_id', $req->id)
                        ->pluck('to_status')
                        ->all();
                    $candidates = array_unique(array_merge(
                        $historicalStatuses,
                        [$req->status?->value],
                    ));

                    $bestOrder = -1;
                    $bestStatus = null;
                    foreach ($candidates as $val) {
                        if ($val === null) {
                            continue;
                        }
                        $enum = RequestStatus::tryFrom((string) $val);
                        if ($enum === null) {
                            continue;
                        }
                        $order = $enum->lifecycleOrder();
                        if ($order > $bestOrder) {
                            $bestOrder = $order;
                            $bestStatus = $enum;
                        }
                    }

                    if ($bestStatus === null) {
                        $stats['skipped']++;
                        continue;
                    }

                    // Идемпотентность: пропускаем если уже совпадает.
                    if ($req->peak_status === $bestStatus) {
                        $stats['skipped']++;
                        continue;
                    }

                    if (! $dryRun) {
                        DB::table('requests')
                            ->where('id', $req->id)
                            ->update(['peak_status' => $bestStatus->value]);
                    }
                    $stats['updated']++;
                }
            });

        $this->newLine();
        $this->table(
            ['scanned', 'updated', 'skipped'],
            [[$stats['scanned'], $stats['updated'], $stats['skipped']]],
        );

        return self::SUCCESS;
    }
}
