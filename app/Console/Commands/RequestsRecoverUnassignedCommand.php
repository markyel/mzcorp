<?php

namespace App\Console\Commands;

use App\Enums\ClosedLostReason;
use App\Enums\RequestStatus;
use App\Models\Request;
use App\Models\RequestStateChange;
use App\Services\Request\AssignmentService;
use App\Services\Request\RequestStateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hourly recovery для нераспределённых заявок (assigned_user_id IS NULL).
 *
 * Бизнес-кейс (M-2026-1031..1061 от 18 мая): во время деплоя AssignmentService
 * pipeline `RequestItemPersister::persist()` сохранил items, но autoAssign упал
 * молча — Request остался Pending+unassigned. Items есть, менеджера нет, в
 * пулах не видно никому. Раньше требовало ручной диагностики и tinker-фикса.
 *
 * Логика:
 *  - **Pending + items есть** → `AssignmentService::autoAssign()` (идемпотентный
 *    повторный вызов). Записывается state_change event='auto_recovery'.
 *  - **Pending + items НЕТ + старше threshold** → `RequestStateService::systemCloseLost()`
 *    с reason=ParserNoContent. Письмо остаётся в /MZ/ для возможной ручной
 *    реанимации секретарём.
 *
 * Schedule: hourly (routes/console.php).
 *
 * Usage:
 *   php artisan requests:recover-unassigned                 # apply
 *   php artisan requests:recover-unassigned --dry-run       # preview
 *   php artisan requests:recover-unassigned --threshold=4   # ждать 4ч (default 2)
 */
class RequestsRecoverUnassignedCommand extends Command
{
    protected $signature = 'requests:recover-unassigned
        {--dry-run : Только показать что произошло бы, без изменений}
        {--threshold=2 : Часы ожидания перед закрытием пустой заявки (default 2)}';

    protected $description = 'Восстановить нераспределённые Pending-заявки: autoAssign если есть items, close_lost если пусто >threshold часов.';

    public function handle(AssignmentService $assignment, RequestStateService $state): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $thresholdHours = max(1, (int) $this->option('threshold'));
        $cutoff = now()->subHours($thresholdHours);

        // Находим все Pending без менеджера. Делим по факту наличия активных items.
        $candidates = Request::query()
            ->whereNull('assigned_user_id')
            ->where('status', RequestStatus::Pending->value)
            ->orderBy('created_at')
            ->get(['id', 'internal_code', 'status', 'client_email', 'created_at']);

        if ($candidates->isEmpty()) {
            $this->info('Нет нераспределённых Pending-заявок.');
            return self::SUCCESS;
        }

        $withItems = collect();
        $emptyOld = collect();
        $emptyFresh = collect();

        foreach ($candidates as $r) {
            $hasItems = DB::table('request_items')
                ->where('request_id', $r->id)
                ->where('is_active', true)
                ->exists();

            if ($hasItems) {
                $withItems->push($r);
            } elseif ($r->created_at < $cutoff) {
                $emptyOld->push($r);
            } else {
                $emptyFresh->push($r);
            }
        }

        $this->info(sprintf(
            'Найдено: %d Pending без менеджера. С items: %d, пустых старше %dч: %d, свежих пустых: %d (ждут).',
            $candidates->count(),
            $withItems->count(),
            $thresholdHours,
            $emptyOld->count(),
            $emptyFresh->count(),
        ));

        if ($withItems->isNotEmpty()) {
            $this->line('');
            $this->line('— Recovery autoAssign (с items) —');
            $this->table(
                ['Code', 'Email', 'Created'],
                $withItems->map(fn ($r) => [
                    $r->internal_code,
                    $r->client_email,
                    $r->created_at?->format('Y-m-d H:i'),
                ])->all(),
            );
        }

        if ($emptyOld->isNotEmpty()) {
            $this->line('');
            $this->line('— Close as parser_no_content (пусто, старше threshold) —');
            $this->table(
                ['Code', 'Email', 'Created', 'Age(h)'],
                $emptyOld->map(fn ($r) => [
                    $r->internal_code,
                    $r->client_email,
                    $r->created_at?->format('Y-m-d H:i'),
                    (int) now()->diffInHours($r->created_at),
                ])->all(),
            );
        }

        if ($dryRun) {
            $this->warn('--dry-run: ничего не изменено.');
            return self::SUCCESS;
        }

        // Применение.
        $assignedOk = 0;
        $assignedFail = 0;
        $closedOk = 0;
        $closedFail = 0;

        foreach ($withItems as $row) {
            $r = Request::find($row->id);
            if (! $r) {
                continue;
            }
            try {
                $mgr = $assignment->autoAssign($r);
                if ($mgr) {
                    $assignedOk++;
                    // Audit-метка: это была recovery (не штатное assignment),
                    // отличается от system_initial — пригодится РОПу при
                    // post-mortem'е сбоев pipeline.
                    try {
                        RequestStateChange::create([
                            'request_id' => $r->id,
                            'from_status' => RequestStatus::Pending->value,
                            'to_status' => $r->fresh()->status->value,
                            'by_user_id' => null,
                            'event' => 'auto_recovery',
                            'comment' => sprintf(
                                'Recovery: items были, менеджер не назначен (вероятно сбой pipeline). '
                                .'Назначен #%d %s.',
                                $mgr->id,
                                $mgr->name,
                            ),
                            'payload' => ['assigned_to_user_id' => $mgr->id],
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('RequestsRecoverUnassignedCommand: state_change failed', [
                            'request_id' => $r->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    $this->info(sprintf('  ✓ %s → #%d %s', $r->internal_code, $mgr->id, $mgr->name));
                } else {
                    $assignedFail++;
                    $this->warn(sprintf('  ✗ %s → autoAssign вернул NULL', $r->internal_code));
                }
            } catch (\Throwable $e) {
                $assignedFail++;
                $this->error(sprintf('  ✗ %s → exception: %s', $r->internal_code, $e->getMessage()));
                Log::error('RequestsRecoverUnassignedCommand: autoAssign exception', [
                    'request_id' => $r->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($emptyOld as $row) {
            $r = Request::find($row->id);
            if (! $r) {
                continue;
            }
            try {
                $state->systemCloseLost(
                    $r,
                    ClosedLostReason::ParserNoContent,
                    sprintf(
                        'Парсер не нашёл позиций за %dч после поступления. Автозакрытие. '
                        .'При необходимости реанимировать вручную из карточки.',
                        $thresholdHours,
                    ),
                );
                $closedOk++;
                $this->info(sprintf('  ✓ %s → closed_lost (parser_no_content)', $r->internal_code));
            } catch (\Throwable $e) {
                $closedFail++;
                $this->error(sprintf('  ✗ %s → exception: %s', $r->internal_code, $e->getMessage()));
                Log::error('RequestsRecoverUnassignedCommand: closeLost exception', [
                    'request_id' => $r->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->line('');
        $this->info(sprintf(
            'Итого: assign ok=%d / fail=%d, close ok=%d / fail=%d.',
            $assignedOk,
            $assignedFail,
            $closedOk,
            $closedFail,
        ));

        return self::SUCCESS;
    }
}
