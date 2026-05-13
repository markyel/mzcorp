<?php

namespace App\Services\Request;

use App\Enums\ClosedLostReason;
use App\Enums\RequestStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Request;
use App\Models\RequestStateChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ручные переходы статуса заявки (Phase 1.10).
 *
 * Single source of truth для status-transitions:
 *  - validation (RequestStatus::allowedTransitions);
 *  - authorization (assigned manager + privileged);
 *  - audit-запись в request_state_changes;
 *  - на терминал: closed_at + closed_lost_reason / comment.
 *
 * Pause / resume — отдельный сервис RequestPauseService, потому что у них
 * особая семантика (paused_from_status preservation).
 */
class RequestStateService
{
    /**
     * @param  array{closed_lost_reason?: string, closed_lost_comment?: string, comment?: string, event?: string, payload?: array}  $context
     */
    public function transitionTo(
        Request $request,
        RequestStatus $to,
        ?User $author,
        array $context = [],
    ): Request {
        $this->ensureCanTransition($request, $author);

        $from = $request->status;
        if ($from === $to) {
            // Идемпотентность — повторный transition в тот же статус no-op.
            return $request;
        }

        // Pause — не через этот сервис.
        if ($to === RequestStatus::Paused) {
            throw new \LogicException(
                'Use RequestPauseService::pauseUntil() для pause-перехода.'
            );
        }

        // Карта переходов из текущего статуса.
        $allowed = $from->allowedTransitions();
        if (! in_array($to, $allowed, true)) {
            throw new \DomainException(sprintf(
                'Запрещённый переход: %s → %s. Разрешены: [%s].',
                $from->value,
                $to->value,
                implode(', ', array_map(fn ($s) => $s->value, $allowed)),
            ));
        }

        // Closed_lost требует reason.
        $closedLostReason = null;
        $closedLostComment = null;
        if ($to === RequestStatus::ClosedLost) {
            $reasonStr = (string) ($context['closed_lost_reason'] ?? '');
            $closedLostReason = ClosedLostReason::tryFrom($reasonStr);
            if ($closedLostReason === null) {
                throw new \DomainException(
                    'Переход в closed_lost требует валидной closed_lost_reason из ClosedLostReason enum.'
                );
            }
            $closedLostComment = isset($context['closed_lost_comment'])
                ? trim((string) $context['closed_lost_comment'])
                : null;
            if ($closedLostReason->requiresComment() && ($closedLostComment === null || $closedLostComment === '')) {
                throw new \DomainException(
                    'Эта причина закрытия требует комментария оператора.'
                );
            }
        }

        DB::transaction(function () use ($request, $from, $to, $author, $context, $closedLostReason, $closedLostComment) {
            $request->status = $to;

            // Terminal: closed_at + lost-reason if applicable.
            if ($to->isTerminal()) {
                $request->closed_at = now();
                if ($to === RequestStatus::ClosedLost) {
                    $request->closed_lost_reason = $closedLostReason->value;
                    $request->closed_lost_comment = $closedLostComment;
                }
            }

            $request->save();

            // Audit.
            RequestStateChange::create([
                'request_id' => $request->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'by_user_id' => $author?->id,
                'event' => (string) ($context['event'] ?? 'manual'),
                'comment' => $context['comment'] ?? null,
                'payload' => array_merge($context['payload'] ?? [], array_filter([
                    'closed_lost_reason' => $closedLostReason?->value,
                ])),
            ]);
        });

        Log::info('RequestStateService: transition', [
            'request_id' => $request->id,
            'from' => $from->value,
            'to' => $to->value,
            'by' => $author?->id,
            'event' => $context['event'] ?? 'manual',
        ]);

        return $request;
    }

    /**
     * Записать «initial» audit-event при создании заявки (без status-update).
     * Вызывается из ParseRequestItemsJob после autoAssign.
     */
    public function recordSystemInitial(Request $request, ?User $assignedTo = null, string $note = ''): void
    {
        RequestStateChange::create([
            'request_id' => $request->id,
            'from_status' => null,
            'to_status' => $request->status->value,
            'by_user_id' => null,
            'event' => 'system_initial',
            'comment' => $note ?: null,
            'payload' => $assignedTo ? ['assigned_to_user_id' => $assignedTo->id] : null,
        ]);
    }

    private function ensureCanTransition(Request $request, ?User $author): void
    {
        if ($author === null) {
            abort(403);
        }
        $privileged = $author->hasAnyRole([
            RoleEnum::HeadOfSales->value,
            RoleEnum::Director->value,
        ]);
        if ($privileged) {
            return;
        }
        if ($author->hasRole(RoleEnum::Secretary->value)) {
            abort(403, 'Секретарь только просматривает заявки.');
        }
        if ($request->assigned_user_id === $author->id) {
            return;
        }
        abort(403, 'Менять статус может только assigned-менеджер или РОП.');
    }
}
