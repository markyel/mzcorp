<?php

namespace App\Console\Commands;

use App\Enums\AiDecisionStatus;
use App\Enums\DetectorType;
use App\Enums\RequestStatus;
use App\Models\AiDecision;
use App\Services\DocumentDetector\AiDecisionService;
use Illuminate\Console\Command;

/**
 * Прогоняет failed AiDecisions заново.
 *
 * Use case: после bug-fix в AiDecisionService::apply (например, фикс
 * 2026-05-22 — systemTransition для auto-apply) старые failed-decisions
 * остались с статусом «failed» навсегда. Эта команда возвращает их в
 * Suggested и дёргает apply повторно. Особенно полезно для outbound
 * детекторов (clarification / quotation_full / invoice).
 *
 *  --type=...    отфильтровать по detector_type (можно несколько через запятую)
 *  --since=7d    только decisions за период (7d, 24h, 30d)
 *  --apply       выполнить (без --apply — dry-run, только показать)
 */
class AiDecisionsRetryFailedCommand extends Command
{
    protected $signature = 'ai-decisions:retry-failed
        {--type= : Фильтр по detector_type (через запятую)}
        {--since=30d : За какой период брать failed (7d/24h/30d)}
        {--apply : Применить (по умолчанию dry-run)}
        {--skip-terminal : Decisions для terminal-заявок сразу пометить Dismissed без попытки retry}';

    protected $description = 'Прогнать failed AiDecisions заново (после bug-fix в apply()).';

    public function handle(AiDecisionService $service): int
    {
        $apply = (bool) $this->option('apply');
        $skipTerminal = (bool) $this->option('skip-terminal');
        $sinceOpt = (string) $this->option('since');
        $typeOpt = (string) ($this->option('type') ?? '');

        $since = $this->parseSince($sinceOpt);
        if ($since === null) {
            $this->error("Не удалось разобрать --since={$sinceOpt}. Используй формат: 7d, 24h, 30d.");

            return self::INVALID;
        }

        $query = AiDecision::query()
            ->where('status', AiDecisionStatus::Failed->value)
            ->where('created_at', '>=', $since);

        if ($typeOpt !== '') {
            $types = array_map('trim', explode(',', $typeOpt));
            $query->whereIn('detector_type', $types);
        }

        $rows = $query->orderBy('id')->get();

        if ($rows->isEmpty()) {
            $this->info('Нет failed decisions по фильтру.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Найдено %d failed decisions с %s. Mode: %s',
            $rows->count(),
            $since->toDateTimeString(),
            $apply ? 'APPLY' : 'DRY-RUN',
        ));
        $this->newLine();

        $stats = ['retried' => 0, 'still_failed' => 0, 'succeeded' => 0, 'skipped' => 0, 'dismissed_terminal' => 0];

        foreach ($rows as $d) {
            $type = $d->detector_type;
            $typeStr = $type instanceof DetectorType ? $type->value : (string) $type;
            $line = sprintf(
                '#%d %s req=%d msg=%d conf=%.2f',
                $d->id,
                $typeStr,
                $d->request_id,
                $d->email_message_id,
                (float) $d->confidence,
            );

            // --skip-terminal: для заявок в closed_lost/closed_won/paid не пытаемся
            // ретраить — state machine откажет в переходе. Сразу Dismissed
            // с пометкой dismiss_reason=terminal_request_status.
            if ($skipTerminal) {
                // Request model кастит status в enum, поэтому Builder::value
                // возвращает RequestStatus, а не string. Нормализуем оба случая.
                $reqStatusRaw = $d->request()->value('status');
                $reqStatusEnum = $reqStatusRaw instanceof RequestStatus
                    ? $reqStatusRaw
                    : (is_string($reqStatusRaw) ? RequestStatus::tryFrom($reqStatusRaw) : null);
                $reqStatusStr = $reqStatusEnum?->value ?? 'unknown';
                if ($reqStatusEnum?->isTerminal()) {
                    if (! $apply) {
                        $this->line('  [DRY-DISMISS] ' . $line . ' (req status=' . $reqStatusStr . ')');
                        $stats['dismissed_terminal']++;

                        continue;
                    }
                    $payload = is_array($d->payload) ? $d->payload : [];
                    unset($payload['apply_error'], $payload['apply_error_class']);
                    $payload['dismiss_reason'] = 'terminal_request_status:' . $reqStatusStr;
                    $d->update([
                        'status' => AiDecisionStatus::Dismissed->value,
                        'payload' => $payload,
                        'applied_at' => now(),
                    ]);
                    $this->line('  ↷ ' . $line . ' → dismissed (terminal=' . $reqStatusStr . ')');
                    $stats['dismissed_terminal']++;

                    continue;
                }
            }

            if (! $apply) {
                $this->line('  [DRY] ' . $line);
                $stats['retried']++;

                continue;
            }

            // Reset → Suggested. payload сохраняем, apply_error чистим.
            $payload = is_array($d->payload) ? $d->payload : [];
            unset($payload['apply_error'], $payload['apply_error_class']);
            $d->update([
                'status' => AiDecisionStatus::Suggested->value,
                'payload' => $payload,
                'applied_at' => null,
                'applied_by_user_id' => null,
            ]);

            $fresh = $service->apply($d->fresh(), null, ['auto' => true]);
            $stats['retried']++;
            $newStatus = $fresh->status instanceof AiDecisionStatus ? $fresh->status->value : (string) $fresh->status;

            if ($fresh->status === AiDecisionStatus::Failed) {
                $stats['still_failed']++;
                $err = is_array($fresh->payload) ? ($fresh->payload['apply_error'] ?? '—') : '—';
                $errClass = is_array($fresh->payload) ? ($fresh->payload['apply_error_class'] ?? '—') : '—';
                $this->warn('  ✗ ' . $line . ' → ' . $newStatus . ' (' . $errClass . ': ' . $err . ')');
            } elseif ($fresh->status === AiDecisionStatus::AutoApplied
                || $fresh->status === AiDecisionStatus::ManuallyConfirmed) {
                $stats['succeeded']++;
                $this->info('  ✓ ' . $line . ' → ' . $newStatus);
            } else {
                $stats['skipped']++;
                $this->line('  · ' . $line . ' → ' . $newStatus);
            }
        }

        $this->newLine();
        $this->table(
            ['retried', 'succeeded', 'still_failed', 'skipped', 'dismissed_terminal'],
            [[
                $stats['retried'],
                $stats['succeeded'],
                $stats['still_failed'],
                $stats['skipped'],
                $stats['dismissed_terminal'],
            ]],
        );

        return self::SUCCESS;
    }

    private function parseSince(string $opt): ?\Illuminate\Support\Carbon
    {
        if (! preg_match('/^(\d+)([hdw])$/i', $opt, $m)) {
            return null;
        }
        $n = (int) $m[1];
        $unit = strtolower($m[2]);

        return match ($unit) {
            'h' => now()->subHours($n),
            'd' => now()->subDays($n),
            'w' => now()->subWeeks($n),
            default => null,
        };
    }
}
