<?php

namespace App\Console\Commands;

use App\Enums\ClosedLostReason;
use App\Enums\RequestStatus;
use App\Models\Request;
use App\Models\RequestStateChange;
use App\Services\Request\AssignmentService;
use App\Services\Request\AutoCloseDecisionService;
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

    public function handle(
        AssignmentService $assignment,
        RequestStateService $state,
        AutoCloseDecisionService $autoClose,
    ): int {
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
            $this->line('— Empty + older than threshold (will ask LLM) —');
            $this->table(
                ['Code', 'Email', 'Created', 'Age(h)'],
                $emptyOld->map(fn ($r) => [
                    $r->internal_code,
                    $r->client_email,
                    $r->created_at?->format('Y-m-d H:i'),
                    // diffInHours в Carbon ≥3 возвращает signed (отрицательный
                    // для прошлого) — берём abs для отображения возраста.
                    (int) abs(now()->diffInHours($r->created_at)),
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

        // LLM-валидатор перед автозакрытием. Для каждой «пустой+старой»
        // заявки спрашиваем gpt-4o-mini: реально ли это запрос (keep) или
        // безопасно автозакрыть (close). При сомнении / падении LLM —
        // keep (safe fallback), менеджер разберёт.
        $kept = 0;
        $keepFail = 0;
        foreach ($emptyOld as $row) {
            $r = Request::find($row->id);
            if (! $r) {
                continue;
            }
            $email = $r->emailMessage;
            if (! $email) {
                // Нет email — нечего показать LLM, закрываем как раньше.
                try {
                    $state->systemCloseLost(
                        $r,
                        ClosedLostReason::ParserNoContent,
                        sprintf('Парсер не нашёл позиций за %dч + email_message отсутствует. Автозакрытие.', $thresholdHours),
                    );
                    $closedOk++;
                    $this->info(sprintf('  ✓ %s → close (no email)', $r->internal_code));
                } catch (\Throwable $e) {
                    $closedFail++;
                    $this->error(sprintf('  ✗ %s → close exception: %s', $r->internal_code, $e->getMessage()));
                }
                continue;
            }

            try {
                $decision = $autoClose->decide($r, $email);
            } catch (\Throwable $e) {
                // Defensive: AutoCloseDecisionService сам должен ловить и
                // возвращать keep при ошибке, но catch и здесь.
                $decision = [
                    'verdict' => 'keep',
                    'confidence' => 0.0,
                    'reasoning' => 'Exception в AutoCloseDecisionService: ' . $e->getMessage(),
                ];
            }

            $confPct = (int) round(($decision['confidence'] ?? 0) * 100);

            if ($decision['verdict'] === 'close') {
                try {
                    $state->systemCloseLost(
                        $r,
                        ClosedLostReason::ParserNoContent,
                        sprintf(
                            'Авто-LLM закрытие (%d%%): %s',
                            $confPct,
                            $decision['reasoning'],
                        ),
                    );
                    // Audit в state_change.payload — для пула «Автозакрытые».
                    RequestStateChange::where('request_id', $r->id)
                        ->where('event', 'system_close_lost')
                        ->latest('id')
                        ->first()
                        ?->update([
                            'payload' => [
                                'closed_lost_reason' => ClosedLostReason::ParserNoContent->value,
                                'llm_verdict' => 'close',
                                'llm_confidence' => $decision['confidence'],
                                'llm_reasoning' => $decision['reasoning'],
                            ],
                        ]);
                    $closedOk++;
                    $this->info(sprintf(
                        '  ✓ %s → close [%d%%] %s',
                        $r->internal_code,
                        $confPct,
                        mb_substr($decision['reasoning'], 0, 80),
                    ));
                } catch (\Throwable $e) {
                    $closedFail++;
                    $this->error(sprintf('  ✗ %s → close exception: %s', $r->internal_code, $e->getMessage()));
                    Log::error('RequestsRecoverUnassignedCommand: closeLost exception', [
                        'request_id' => $r->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                continue;
            }

            // verdict === 'keep' — LLM считает что это похоже на запрос,
            // отдаём менеджеру через autoAssign. Если autoAssign ничего не
            // вернёт (нет доступных менеджеров) — заявка останется Pending,
            // следующий tick попробует снова (LLM кэш не делаем — повторный
            // вызов через час недорог).
            try {
                $mgr = $assignment->autoAssign($r);
                if ($mgr) {
                    $kept++;
                    RequestStateChange::create([
                        'request_id' => $r->id,
                        'from_status' => RequestStatus::Pending->value,
                        'to_status' => $r->fresh()->status->value,
                        'by_user_id' => null,
                        'event' => 'auto_recovery_llm_kept',
                        'comment' => sprintf(
                            'LLM keep (%d%%): %s · Назначен #%d %s.',
                            $confPct,
                            $decision['reasoning'],
                            $mgr->id,
                            $mgr->name,
                        ),
                        'payload' => [
                            'assigned_to_user_id' => $mgr->id,
                            'llm_verdict' => 'keep',
                            'llm_confidence' => $decision['confidence'],
                            'llm_reasoning' => $decision['reasoning'],
                        ],
                    ]);
                    $this->info(sprintf(
                        '  ↻ %s → keep [%d%%] → #%d %s | %s',
                        $r->internal_code,
                        $confPct,
                        $mgr->id,
                        $mgr->name,
                        mb_substr($decision['reasoning'], 0, 60),
                    ));
                } else {
                    $keepFail++;
                    $this->warn(sprintf(
                        '  ? %s → keep [%d%%] но autoAssign=NULL (нет available менеджеров?)',
                        $r->internal_code,
                        $confPct,
                    ));
                }
            } catch (\Throwable $e) {
                $keepFail++;
                $this->error(sprintf('  ✗ %s → keep exception: %s', $r->internal_code, $e->getMessage()));
                Log::error('RequestsRecoverUnassignedCommand: keep autoAssign exception', [
                    'request_id' => $r->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->line('');
        $this->info(sprintf(
            'Итого: assign(items) ok=%d/fail=%d · LLM-keep ok=%d/fail=%d · LLM-close ok=%d/fail=%d.',
            $assignedOk,
            $assignedFail,
            $kept,
            $keepFail,
            $closedOk,
            $closedFail,
        ));

        return self::SUCCESS;
    }
}
