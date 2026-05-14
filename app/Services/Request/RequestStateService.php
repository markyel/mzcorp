<?php

namespace App\Services\Request;

use App\Enums\ClosedLostReason;
use App\Enums\RequestStatus;
use App\Enums\Role as RoleEnum;
use App\Models\EmailMessage;
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
    public function __construct(
        private readonly AttentionService $attention,
    ) {
    }

    /**
     * @param  array{closed_lost_reason?: string, closed_lost_comment?: string, closed_lost_quote?: string, closed_lost_source_message_id?: int, comment?: string, event?: string, payload?: array}  $context
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
                    // Foundation §7.4: цитата из inbound-письма + ссылка
                    // на это письмо (заполняется InboundIntentClassifier'ом
                    // или вручную через CloseLostDialog).
                    if (isset($context['closed_lost_quote'])) {
                        $request->closed_lost_quote = trim((string) $context['closed_lost_quote']) ?: null;
                    }
                    if (isset($context['closed_lost_source_message_id'])) {
                        $request->closed_lost_source_message_id = (int) $context['closed_lost_source_message_id'] ?: null;
                    }
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

            // Phase 1.11 (Foundation §5.3): пересчёт attention_required_at
            // после каждого перехода. compute() читает request_state_changes
            // только что вставленный row — поэтому делаем внутри транзакции
            // ПОСЛЕ insert'а. Для terminal/paid → AttentionService сам
            // вернёт NULL и очистит поля.
            $this->attention->recompute($request);
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
     * Реанимация closed_lost заявки (Foundation §5.2).
     *
     * Triggered InboundReplyLinker'ом когда клиент написал после «тихого»
     * закрытия. НЕ создаёт новую Request — возвращает эту в работу
     * (status=in_progress), сохраняя историю в state_change.payload.
     * closed_lost_* поля очищаются, потому что заявка снова активна;
     * reanimated_at/_count фиксируют факт реанимации для UI-маркера.
     *
     * Реанимировать можно только из closed_lost — closed_won не трогаем
     * (там сделка состоялась, новое письмо клиента = новый запрос).
     */
    public function reanimate(Request $request, ?User $author, EmailMessage $sourceMessage): Request
    {
        if ($request->status !== RequestStatus::ClosedLost) {
            throw new \DomainException(sprintf(
                'Реанимация доступна только из closed_lost, текущий статус: %s.',
                $request->status->value,
            ));
        }

        $from = $request->status;
        // Foundation §5.2: возврат в qualifying. У нас нет qualifying —
        // используем in_progress (заявка уже была назначена + распарсена,
        // менеджер сразу видит её в Pool).
        $to = RequestStatus::InProgress;

        $snapshot = [
            'closed_at' => $request->closed_at?->toIso8601String(),
            'closed_lost_reason' => $request->closed_lost_reason,
            'closed_lost_comment' => $request->closed_lost_comment,
            'closed_lost_quote' => $request->closed_lost_quote,
            'closed_lost_source_message_id' => $request->closed_lost_source_message_id,
        ];

        DB::transaction(function () use ($request, $to, $author, $from, $snapshot, $sourceMessage) {
            $newCount = (int) ($request->reanimated_count ?? 0) + 1;
            $request->status = $to;
            $request->closed_at = null;
            $request->closed_lost_reason = null;
            $request->closed_lost_comment = null;
            $request->closed_lost_quote = null;
            $request->closed_lost_source_message_id = null;
            $request->reanimated_at = now();
            $request->reanimated_count = $newCount;
            $request->save();

            RequestStateChange::create([
                'request_id' => $request->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'by_user_id' => $author?->id,
                'event' => 'reanimate',
                'comment' => 'Клиент написал после закрытия — реанимация',
                'payload' => [
                    'reanimate_count' => $newCount,
                    'restored_from' => $snapshot,
                    'source_email_message_id' => $sourceMessage->id,
                ],
            ]);

            // Phase 1.11: после reanimate ставим attention now+SLA-дедлайн
            // (recompute учитывает новый статус InProgress).
            $this->attention->recompute($request);
        });

        Log::info('RequestStateService: reanimated', [
            'request_id' => $request->id,
            'previous_count' => $snapshot['closed_at'] !== null ? $request->reanimated_count - 1 : 0,
            'new_count' => $request->reanimated_count,
            'source_email_message_id' => $sourceMessage->id,
            'restored_from' => $snapshot,
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

        // Phase 1.11: первый дедлайн для свеженазначенной заявки.
        $this->attention->recompute($request);
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
