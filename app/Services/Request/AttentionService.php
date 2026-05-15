<?php

namespace App\Services\Request;

use App\Enums\AttentionReason;
use App\Enums\RequestStatus;
use App\Models\Request;
use App\Models\RequestStateChange;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Attention-механизм (Foundation §5.3 + §5.5).
 *
 * Single source of truth для расчёта `attention_required_at` и
 * `attention_reason`. Вызывается:
 *  - RequestStateService::transitionTo() после save()
 *  - RequestPauseService::pauseUntil()  → clear (NULL, NULL)
 *  - RequestPauseService::resume()       → set (now, postponed_resume)
 *  - RequestItemPersister после первого auto-assign
 *  - Console: requests:check-attention (sweeps attention_level)
 *
 * Дедлайны конфигурируемые через `app_setting('attention.*')` поверх
 * defaults Foundation §5.5. «Рабочие часы» = Пн-Пт 9-18 Europe/Moscow.
 */
class AttentionService
{
    private const TZ = 'Europe/Moscow';
    private const BUSINESS_START_HOUR = 9;
    private const BUSINESS_END_HOUR = 18;

    /**
     * Пересчитать attention для заявки и сохранить. Сбрасывает
     * attention_level в 0 — overdue определит cron-sweep.
     *
     * Sticky-reason'ы НЕ затираются recompute:
     *   Manual          — снимается только clearManual()
     *   ClientReplied   — снимается onManagerOpened()
     *   FreshAssignment — снимается onManagerOpened()
     */
    public function recompute(Request $request): void
    {
        if ($this->isStickyReason($request)) {
            return;
        }

        [$at, $reason] = $this->compute($request);

        $request->forceFill([
            'attention_required_at' => $at,
            'attention_reason' => $reason?->value,
            'attention_level' => 0,
        ])->save();
    }

    /**
     * Очистить attention (terminal / paused / pending). Заодно снимает
     * manual flag — на терминальных переходах он теряет смысл.
     */
    public function clear(Request $request): void
    {
        $request->forceFill([
            'attention_required_at' => null,
            'attention_reason' => null,
            'attention_level' => 0,
            'attention_manual_by_user_id' => null,
        ])->save();
    }

    /**
     * Hook: клиент прислал inbound в активную заявку. Ставим
     * reason=ClientReplied + attention_level=1 (info, amber/sky).
     *
     * Не затирает Manual — он сильнее.
     *
     * НЕ ставим если статус — терминальный или Pending. Reanimate для
     * ClosedLost обрабатывается отдельно в RequestStateService::reanimate.
     */
    public function onClientReplied(Request $request): void
    {
        if (in_array($request->status, $this->silentStatuses(), true)) {
            return;
        }
        if ($this->isManualSet($request)) {
            return;
        }

        $request->forceFill([
            'attention_required_at' => now(),
            'attention_reason' => AttentionReason::ClientReplied->value,
            'attention_level' => 1,
        ])->save();
    }

    /**
     * Hook: заявка только что назначена менеджеру (auto-assign / sticky /
     * round-robin / РОП-reassign). Ставит reason=FreshAssignment, level=1
     * info. Снимается onManagerOpened.
     */
    public function onAssigned(Request $request): void
    {
        if (in_array($request->status, $this->silentStatuses(), true)) {
            return;
        }
        if ($this->isManualSet($request)) {
            return;
        }

        $request->forceFill([
            'attention_required_at' => now(),
            'attention_reason' => AttentionReason::FreshAssignment->value,
            'attention_level' => 1,
        ])->save();
    }

    /**
     * Hook: менеджер открыл карточку. Снимает info-флаги (ClientReplied /
     * FreshAssignment) — recompute вернёт обычный SlaBreach по статусу.
     * SlaBreach / PostponedResume / Manual не трогаем.
     */
    public function onManagerOpened(Request $request): void
    {
        $clearable = [
            AttentionReason::ClientReplied->value,
            AttentionReason::FreshAssignment->value,
        ];
        if (! in_array($request->attention_reason, $clearable, true)) {
            return;
        }
        $this->recompute($request);
    }

    /**
     * Hook: пришёл ответ от поставщика (Phase 3, supplier flow). Аналог
     * onClientReplied, отдельная reason — РОП понимает, чьё именно письмо.
     */
    public function onSupplierReplied(Request $request): void
    {
        if (in_array($request->status, $this->silentStatuses(), true)) {
            return;
        }
        if ($this->isManualSet($request)) {
            return;
        }

        $request->forceFill([
            'attention_required_at' => now(),
            'attention_reason' => AttentionReason::SupplierReplied->value,
            'attention_level' => 1,
        ])->save();
    }

    /**
     * Hook: менеджер передал ход клиенту/поставщику (отправил уточнение,
     * КП, счёт или просто ответ). Снимает info-flags ClientReplied /
     * FreshAssignment / SupplierReplied — заявка должна уйти из top'а.
     * Manual / SlaBreach / PostponedResume не трогаем.
     *
     * После snimaния — recompute вернёт обычный SlaBreach по новому статусу
     * (с дедлайном в будущем → level=0, заявка тонет).
     */
    public function onManagerHandled(Request $request): void
    {
        $clearable = [
            AttentionReason::ClientReplied->value,
            AttentionReason::FreshAssignment->value,
            AttentionReason::SupplierReplied->value,
        ];
        if (! in_array($request->attention_reason, $clearable, true)) {
            return;
        }
        $this->recompute($request);
    }

    /**
     * Ручной флаг attention. Менеджер ставит «вернись ко мне» на свою/
     * делегированную заявку; РОП — на любую. level=1, reason=Manual,
     * attention_manual_by_user_id=$byUser. Sticky: не затирается
     * recompute / onClientReplied / onManagerOpened.
     */
    public function setManual(Request $request, \App\Models\User $byUser): void
    {
        $request->forceFill([
            'attention_required_at' => now(),
            'attention_reason' => AttentionReason::Manual->value,
            'attention_manual_by_user_id' => $byUser->id,
            'attention_level' => 1,
        ])->save();
    }

    /**
     * Снять manual flag. После снятия — recompute по текущему статусу.
     */
    public function clearManual(Request $request): void
    {
        if (! $this->isManualSet($request)) {
            return;
        }
        $request->forceFill([
            'attention_manual_by_user_id' => null,
            'attention_reason' => null,
            'attention_required_at' => null,
            'attention_level' => 0,
        ])->save();
        $this->recompute($request->fresh());
    }

    private function isManualSet(Request $request): bool
    {
        return $request->attention_reason === AttentionReason::Manual->value;
    }

    /**
     * Sticky-reason: текущая attention НЕ должна затираться recompute().
     */
    private function isStickyReason(Request $request): bool
    {
        return in_array($request->attention_reason, [
            AttentionReason::Manual->value,
            AttentionReason::ClientReplied->value,
            AttentionReason::FreshAssignment->value,
            AttentionReason::SupplierReplied->value,
        ], true);
    }

    /**
     * Sweep: ставит attention_level=1 строкам, у которых дедлайн в прошлом.
     * Сбрасывает attention_level=0 если дедлайн снова в будущем (например,
     * после resume / transitionTo).
     *
     * Возвращает [marked_overdue, reset_to_normal].
     *
     * @return array{0:int, 1:int}
     */
    public function sweepOverdue(): array
    {
        // Шаг 1: собрать ids тех, кто СЕЙЧАС переходит 0 → 1.
        // Делаем это до UPDATE, чтобы знать кому слать notification.
        $newlyOverdueIds = Request::query()
            ->whereNotNull('attention_required_at')
            ->where('attention_level', 0)
            ->where('attention_required_at', '<', now())
            ->whereNotIn('status', $this->silentStatuses())
            ->pluck('id')
            ->all();

        $marked = empty($newlyOverdueIds) ? 0 : Request::query()
            ->whereIn('id', $newlyOverdueIds)
            ->update(['attention_level' => 1]);

        $reset = Request::query()
            ->where('attention_level', 1)
            ->where(function ($q) {
                $q->whereNull('attention_required_at')
                    ->orWhere('attention_required_at', '>=', now())
                    ->orWhereIn('status', $this->silentStatuses());
            })
            ->update(['attention_level' => 0]);

        // Foundation Фаза 2: in-app уведомления менеджерам о overdue-заявках.
        // Шлём ОДНОКРАТНО на переход 0→1 (повторный sweep не шлёт, потому что
        // attention_level уже 1).
        //
        // Routing: если у заявки есть active delegation — шлём ACTING'у
        // (он сейчас фактически работает с этой заявкой, оригинал в отпуске).
        // Иначе — оригинальному assigned-менеджеру.
        if (! empty($newlyOverdueIds)) {
            $overdueRequests = Request::query()
                ->whereIn('id', $newlyOverdueIds)
                ->whereNotNull('assigned_user_id')
                ->with([
                    'assignedUser:id,name',
                    'activeDelegations' => fn ($q) => $q->with('actingUser:id,name'),
                ])
                ->get();
            foreach ($overdueRequests as $req) {
                $targetUser = $req->activeDelegations->first()?->actingUser
                    ?? $req->assignedUser;
                if ($targetUser === null) {
                    continue;
                }
                try {
                    $targetUser->notify(
                        \App\Notifications\RequestAttentionOverdueNotification::from($req),
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        'AttentionService: overdue notification failed (non-fatal)',
                        ['request_id' => $req->id, 'error' => $e->getMessage()],
                    );
                }
            }
        }

        return [$marked, $reset];
    }

    /**
     * Базовый расчёт дедлайна по текущему статусу. Возвращает
     * [Carbon|null, AttentionReason|null].
     *
     * @return array{0: ?Carbon, 1: ?AttentionReason}
     */
    public function compute(Request $request): array
    {
        $status = $request->status;

        // Тихие статусы: терминал, paused, paid (ждём моментального closed_won),
        // pending (парсер ещё не отработал — заявка не у менеджера).
        if (in_array($status, [
            RequestStatus::Paused,
            RequestStatus::ClosedWon,
            RequestStatus::ClosedLost,
            RequestStatus::Paid,
            RequestStatus::Pending,
        ], true)) {
            return [null, null];
        }

        $anchor = $this->statusEnteredAt($request) ?? $request->updated_at ?? now();
        $anchor = CarbonImmutable::instance($anchor);

        return match ($status) {
            RequestStatus::New => [
                $this->addBusinessHours($anchor, $this->cfgInt('new_hours', 1)),
                AttentionReason::SlaBreach,
            ],
            RequestStatus::Assigned => [
                $this->addBusinessHours($anchor, $this->cfgInt('assigned_hours', 4)),
                AttentionReason::SlaBreach,
            ],
            RequestStatus::InProgress => [
                $this->addBusinessHours($anchor, $this->cfgInt('in_progress_hours', 24)),
                AttentionReason::SlaBreach,
            ],
            // Phase «attention reasons minimize» (2026-05-21): для всех
            // не-терминальных статусов с дедлайном reason всегда SlaBreach.
            // Заявка светится красным только когда дедлайн ИСТЁК — до этого
            // в Pool тихо. AwaitingClient / Followup / Partial — это рабочие
            // фазы, не алярм; их визуальная подсветка убрана.
            RequestStatus::AwaitingClientClarification => [
                $this->addBusinessDays($anchor, $this->cfgInt('awaiting_clarification_days', 2)),
                AttentionReason::SlaBreach,
            ],
            RequestStatus::Quoted => [
                $this->addBusinessDays($anchor, $this->cfgInt('quoted_first_followup_days', 3)),
                AttentionReason::SlaBreach,
            ],
            RequestStatus::UnderReview => [
                $this->addBusinessDays($anchor, $this->cfgInt('under_review_days', 3)),
                AttentionReason::SlaBreach,
            ],
            RequestStatus::PostponedUntil => [
                $this->postponedUntilFor($request),
                AttentionReason::PostponedResume,
            ],
            RequestStatus::AwaitingInvoice => [
                $this->addBusinessHours($anchor, $this->cfgInt('awaiting_invoice_hours', 24)),
                AttentionReason::SlaBreach,
            ],
            RequestStatus::Invoiced => [
                $this->addBusinessDays($anchor, $this->cfgInt('invoiced_followup_days', 5)),
                AttentionReason::SlaBreach,
            ],
            default => [null, null],
        };
    }

    /**
     * @return array<int, RequestStatus>
     */
    private function silentStatuses(): array
    {
        return [
            RequestStatus::Paused,
            RequestStatus::ClosedWon,
            RequestStatus::ClosedLost,
            RequestStatus::Pending,
            RequestStatus::Paid,
        ];
    }

    /**
     * Когда заявка ВПЕРВЫЕ перешла в текущий статус. Базируется на
     * request_state_changes: последний row с to_status = current status.
     * Если истории нет (старые заявки до Phase 1.10) — fallback на
     * updated_at.
     */
    private function statusEnteredAt(Request $request): ?Carbon
    {
        $last = RequestStateChange::query()
            ->where('request_id', $request->id)
            ->where('to_status', $request->status->value)
            ->orderByDesc('id')
            ->first(['created_at']);

        return $last?->created_at ?: null;
    }

    /**
     * Для PostponedUntil: в payload последнего state_change должна лежать
     * дата клиента (`postponed_until` ISO8601). Если её нет — fallback
     * +7 рабочих дней от перехода.
     */
    private function postponedUntilFor(Request $request): Carbon
    {
        $last = RequestStateChange::query()
            ->where('request_id', $request->id)
            ->where('to_status', RequestStatus::PostponedUntil->value)
            ->orderByDesc('id')
            ->first(['created_at', 'payload']);

        $payload = $last?->payload ?? [];
        $raw = is_array($payload) ? ($payload['postponed_until'] ?? null) : null;
        if (is_string($raw) && $raw !== '') {
            try {
                return Carbon::parse($raw);
            } catch (\Throwable) {
                // fall through
            }
        }

        $anchor = $last?->created_at ?? now();

        return Carbon::instance($this->addBusinessDays(CarbonImmutable::instance($anchor), 7));
    }

    private function cfgInt(string $key, int $default): int
    {
        $value = app_setting('attention.' . $key, $default);

        return (int) $value;
    }

    /**
     * Добавить N рабочих часов к точке времени. Рабочее время:
     * Пн-Пт 9:00-18:00 Europe/Moscow (9 часов в сутки).
     *
     * Используем timestamp-arithmetic (а не Carbon::diffInMinutes) —
     * у Carbon 2 / Carbon 3 разное соглашение по знаку, при ошибке
     * направления получали бы 0 доступных минут и бесконечный цикл.
     */
    public function addBusinessHours(CarbonImmutable $from, int $hours): Carbon
    {
        if ($hours <= 0) {
            return Carbon::instance($from);
        }

        $cursor = $from->setTimezone(self::TZ);
        $remainingMinutes = $hours * 60;
        $safetyIter = 0; // защита от регрессии — не более 10 лет рабочих дней

        while ($remainingMinutes > 0) {
            if (++$safetyIter > 2600) {
                break; // ~10 лет рабочих дней — что-то пошло не так
            }

            $cursor = $this->shiftToNextBusinessMoment($cursor);

            $endOfDay = $cursor->setTime(self::BUSINESS_END_HOUR, 0);
            $availableMinutes = max(0, (int) (($endOfDay->getTimestamp() - $cursor->getTimestamp()) / 60));

            if ($availableMinutes >= $remainingMinutes) {
                $cursor = $cursor->addMinutes($remainingMinutes);
                $remainingMinutes = 0;
                break;
            }

            // Используем всё доступное окно сегодня и идём на 9:00 след. раб. дня.
            $remainingMinutes -= $availableMinutes;
            $cursor = $this->nextBusinessDayStart($cursor);
        }

        return Carbon::instance($cursor);
    }

    /**
     * Добавить N рабочих дней. Если итог попадает на выходной — двигаем
     * вперёд до ближайшего рабочего дня. Время суток сохраняем (если оно
     * вне 9-18 — clamp к 9:00 или 18:00 нет, оставляем — это про «доступно
     * до конца», не про «отрабатывать в рабочие часы»).
     */
    public function addBusinessDays(CarbonImmutable $from, int $days): Carbon
    {
        $cursor = $from->setTimezone(self::TZ);
        $added = 0;
        while ($added < $days) {
            $cursor = $cursor->addDay();
            if (! in_array($cursor->dayOfWeekIso, [6, 7], true)) {
                $added++;
            }
        }

        return Carbon::instance($cursor);
    }

    /**
     * Если cursor в нерабочем интервале (выходной / до 9:00 / после 18:00) —
     * перевести на ближайшие 9:00 рабочего дня. Иначе вернуть как есть.
     */
    private function shiftToNextBusinessMoment(CarbonImmutable $cursor): CarbonImmutable
    {
        // Выходной — двигаемся на понедельник 9:00.
        while (in_array($cursor->dayOfWeekIso, [6, 7], true)) {
            $cursor = $cursor->addDay()->setTime(self::BUSINESS_START_HOUR, 0);
        }

        $hour = $cursor->hour;
        if ($hour < self::BUSINESS_START_HOUR) {
            return $cursor->setTime(self::BUSINESS_START_HOUR, 0);
        }
        if ($hour >= self::BUSINESS_END_HOUR) {
            return $this->nextBusinessDayStart($cursor);
        }

        return $cursor;
    }

    private function nextBusinessDayStart(CarbonImmutable $cursor): CarbonImmutable
    {
        $cursor = $cursor->addDay()->setTime(self::BUSINESS_START_HOUR, 0);
        while (in_array($cursor->dayOfWeekIso, [6, 7], true)) {
            $cursor = $cursor->addDay();
        }

        return $cursor;
    }
}
