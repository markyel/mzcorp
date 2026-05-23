<?php

namespace App\Services\Request;

use App\Enums\ClosedLostReason;
use App\Enums\MailboxType;
use App\Enums\RequestStatus;
use App\Enums\Role as RoleEnum;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\RequestAssignment;
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
        private readonly RequestActivityService $activity,
    ) {
    }

    /**
     * @param  array{closed_lost_reason?: string, closed_lost_comment?: string, closed_lost_quote?: string, closed_lost_source_message_id?: int, comment?: string, event?: string, payload?: array}  $context
     * @param  bool  $systemTransition  Cron / scheduler / автоматический процесс
     *                                  вызывает без User → пропускаем permission
     *                                  check. В audit `by_user_id = null`.
     *                                  Использовать ТОЛЬКО для system actor'ов
     *                                  (cron, jobs), не для UI.
     */
    public function transitionTo(
        Request $request,
        RequestStatus $to,
        ?User $author,
        array $context = [],
        bool $systemTransition = false,
    ): Request {
        if (! $systemTransition) {
            $this->ensureCanTransition($request, $author);
        }

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

            // peak_status — milestone-rollup. lifecycleOrder=-1 для non-milestone
            // (Paused / PostponedUntil / ClosedLost) — peak не сдвигаем; иначе
            // если новый этап «старше» текущего peak — обновляем. Это
            // позволяет UI показывать в чипе «дальше всего достигнутый»
            // статус, а не текущий operational (см. Request::displayedStatus).
            $toOrder = $to->lifecycleOrder();
            if ($toOrder >= 0) {
                $currentPeakOrder = $request->peak_status?->lifecycleOrder() ?? -1;
                if ($toOrder > $currentPeakOrder) {
                    $request->peak_status = $to;
                }
            }

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

            // Pool «Событие»: тип события по новому статусу. silencesAttention
            // для quote_sent / invoice_sent / clarification_sent — заявка
            // тонет вниз Pool, ход за клиентом.
            $activityType = match ($to) {
                RequestStatus::AwaitingClientClarification => \App\Enums\RequestActivityType::ClarificationSent,
                RequestStatus::Quoted => \App\Enums\RequestActivityType::QuoteSent,
                RequestStatus::Invoiced => \App\Enums\RequestActivityType::InvoiceSent,
                RequestStatus::Paid => \App\Enums\RequestActivityType::Paid,
                RequestStatus::ClosedWon => \App\Enums\RequestActivityType::ClosedWon,
                RequestStatus::ClosedLost => \App\Enums\RequestActivityType::ClosedLost,
                default => \App\Enums\RequestActivityType::StatusChange,
            };
            $this->activity->touch($request, $activityType);
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
            $request->closed_at = null;
            $request->closed_lost_reason = null;
            $request->closed_lost_comment = null;
            $request->closed_lost_quote = null;
            $request->closed_lost_source_message_id = null;
            $request->reanimated_at = now();
            $request->reanimated_count = $newCount;
            $request->save();

            // Re-assessment ответственного при реанимации:
            //   - письмо в личный ящик активного request_handler → отдаём
            //     ему (sig A, сильнейший);
            //   - текущий assignee archived / без нужной роли → autoAssign
            //     (sig B);
            //   - unavailable (отпуск/болезнь) — НЕ трогаем, человек вернётся
            //     и delegation acting'у даст доступ на время.
            $reassignmentDetails = $this->reassessAssignmentOnReanimate($request, $sourceMessage);

            // Статус — после re-assignment'а: autoAssign внутри (если был
            // вызван) выставил Assigned, нам же нужен InProgress (реанимация
            // = заявка вернулась в работу, как раньше).
            $request->status = $to;
            $request->save();

            RequestStateChange::create([
                'request_id' => $request->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'by_user_id' => $author?->id,
                'event' => 'reanimate',
                'comment' => 'Клиент написал после закрытия — реанимация',
                'payload' => array_filter([
                    'reanimate_count' => $newCount,
                    'restored_from' => $snapshot,
                    'source_email_message_id' => $sourceMessage->id,
                    'reassignment' => $reassignmentDetails,
                ]),
            ]);

            // Phase 1.11: после reanimate ставим attention now+SLA-дедлайн
            // (recompute учитывает новый статус InProgress).
            $this->attention->recompute($request);

            $this->activity->touch($request, \App\Enums\RequestActivityType::Reanimated);
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
     * Пересчёт ответственного при реанимации closed_lost заявки.
     *
     * Два независимых сигнала меняют менеджера:
     *
     *   A) **Direct mailbox** — письмо пришло в личный почтовый ящик
     *      активного request_handler. Самый сильный сигнал: клиент написал
     *      персонально менеджеру X. Переподчиняем владельцу ящика, даже
     *      если текущий assignee активен (личный канал важнее sticky).
     *
     *   B) **Архивный assignee** — текущий ответственный заархивирован
     *      (уволен) или потерял request_handler роль. Запускаем
     *      `AssignmentService::autoAssign` — full sticky + RR, выберется
     *      активный менеджер.
     *
     * Unavailable (отпуск / болезнь) — НЕ повод переподчинять. Менеджер
     * вернётся; если на время отсутствия открыто delegation — acting
     * получит доступ автоматически через `Request::isAccessibleBy`.
     *
     * @return array{kind: string, from_user_id: ?int, to_user_id: int}|null
     *         null если переподчинения не было (текущий активный assignee
     *         остался прежним).
     */
    private function reassessAssignmentOnReanimate(Request $request, EmailMessage $sourceMessage): ?array
    {
        $previousAssigneeId = $request->assigned_user_id;

        // Signal A: direct mailbox owner override.
        $directOwner = $this->resolveDirectMailboxOwner($sourceMessage);
        if ($directOwner !== null && $directOwner->id !== $previousAssigneeId) {
            $this->reassignDuringReanimate($request, $directOwner, 'reanimate_direct_mailbox');

            return [
                'kind' => 'direct_mailbox',
                'from_user_id' => $previousAssigneeId,
                'to_user_id' => $directOwner->id,
            ];
        }

        // Signal B: текущий assignee архивирован / не подходит по роли.
        // Unavailable (отпуск) намеренно НЕ триггерит — человек вернётся.
        $assigneeStillEligible = $previousAssigneeId !== null
            && User::query()
                ->active()
                ->role(RoleEnum::requestHandlerRoles())
                ->whereKey($previousAssigneeId)
                ->exists();

        if (! $assigneeStillEligible) {
            $newAssignee = app(AssignmentService::class)->autoAssign($request);
            if ($newAssignee !== null && $newAssignee->id !== $previousAssigneeId) {
                return [
                    'kind' => 'archived_reassign',
                    'from_user_id' => $previousAssigneeId,
                    'to_user_id' => $newAssignee->id,
                ];
            }
        }

        return null;
    }

    /**
     * Personal mailbox owner (активный request_handler) — или null.
     */
    private function resolveDirectMailboxOwner(EmailMessage $message): ?User
    {
        if (! $message->mailbox_id) {
            return null;
        }
        $mailbox = $message->mailbox;
        if (! $mailbox || $mailbox->type !== MailboxType::Personal || ! $mailbox->owner_user_id) {
            return null;
        }

        return User::query()
            ->active()
            ->role(RoleEnum::requestHandlerRoles())
            ->find($mailbox->owner_user_id);
    }

    /**
     * Inline-переподчинение во время реанимации.
     *
     * Не использует `AssignmentService::autoAssign` (тот пересчитывает
     * sticky/RR и трогает status) и не `ReassignService` (тот пишет
     * `manual_reassign:` reason — это не ручная операция). Своя короткая
     * запись с `reanimate_*` reason для аудит-трейла.
     */
    private function reassignDuringReanimate(Request $request, User $newAssignee, string $reason): void
    {
        $request->assigned_user_id = $newAssignee->id;
        $request->assigned_at = now();
        $request->save();

        RequestAssignment::create([
            'request_id' => $request->id,
            'user_id' => $newAssignee->id,
            'by_user_id' => null,
            'reason' => $reason,
            'assigned_at' => now(),
        ]);

        // IMAP-доставка оригинала письма в личный ящик нового assignee +
        // COPY в подпапку MZ|<Фамилия> общего ящика. Идемпотентны.
        $email = $request->emailMessage;
        if ($email) {
            \App\Jobs\Mail\RouteMailToManagerJob::dispatch($email->id, $newAssignee->id);
            \App\Jobs\Mail\DeliverToManagerInboxJob::dispatch($email->id, $newAssignee->id);
        }

        try {
            $newAssignee->notify(
                \App\Notifications\RequestAssignedNotification::from($request->fresh(), $reason),
            );
        } catch (\Throwable $e) {
            Log::warning('RequestStateService: reanimate reassign notification failed (non-fatal)', [
                'request_id' => $request->id,
                'new_assignee_id' => $newAssignee->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Системное закрытие заявки в closed_lost (без author-гейта). Для cron-recovery:
     * `RequestsRecoverUnassignedCommand` находит Pending-заявки без menager
     * и без items старше threshold и закрывает их с causal-reason
     * (`ParserNoContent` по умолчанию).
     *
     * Отличия от transitionTo():
     *  - не требует author (cron не имеет User);
     *  - не проверяет allowedTransitions (Pending→ClosedLost разрешён, но
     *    нужно работать и в edge-кейсах, например New без items);
     *  - не записывает RequestActivity (заявка никогда не была активной для
     *    менеджеров — нечего показывать в Pool «Событие»).
     *
     * НЕ для ручного UI — менеджеры/РОП используют transitionTo().
     */
    public function systemCloseLost(
        Request $request,
        ClosedLostReason $reason,
        string $comment,
    ): Request {
        if ($request->status->isTerminal()) {
            return $request; // идемпотентность
        }

        $from = $request->status;

        DB::transaction(function () use ($request, $from, $reason, $comment) {
            $request->status = RequestStatus::ClosedLost;
            $request->closed_at = now();
            $request->closed_lost_reason = $reason->value;
            $request->closed_lost_comment = $comment;
            $request->save();

            RequestStateChange::create([
                'request_id' => $request->id,
                'from_status' => $from->value,
                'to_status' => RequestStatus::ClosedLost->value,
                'by_user_id' => null,
                'event' => 'system_close_lost',
                'comment' => $comment,
                'payload' => ['closed_lost_reason' => $reason->value],
            ]);

            $this->attention->recompute($request);
        });

        Log::info('RequestStateService: system close_lost', [
            'request_id' => $request->id,
            'internal_code' => $request->internal_code,
            'from' => $from->value,
            'reason' => $reason->value,
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
            RoleEnum::Admin->value,
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
        // Foundation Фаза 2: acting (active delegation) тоже может менять
        // статус на время отсутствия оригинального менеджера.
        if ($request->isDelegatedTo($author)) {
            return;
        }
        abort(403, 'Менять статус может только assigned-менеджер или РОП.');
    }
}
