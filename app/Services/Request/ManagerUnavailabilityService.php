<?php

namespace App\Services\Request;

use App\Enums\Role as RoleEnum;
use App\Models\Request;
use App\Models\RequestDelegation;
use App\Models\RequestStateChange;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Менеджер «недоступен» — отпуск / командировка / больничный
 * (Foundation Фаза 2).
 *
 * Семантика — DELEGATION, не reassignment. Заявка ОСТАЁТСЯ за оригинальным
 * менеджером (`requests.assigned_user_id` НЕ меняется), но на время его
 * отсутствия выбранный коллега получает временный доступ — видит её в
 * Pool, может работать (отвечать клиенту, менять статус, править позиции).
 * Когда оригинал вернулся → delegation закрывается, коллега больше не
 * видит заявку, оригинал продолжает работу.
 *
 *  - markUnavailable(User, Carbon $until, string $reason, User $by)
 *      — выставить `unavailable_until` + `unavailable_reason`.
 *  - markAvailable(User, User $by)
 *      — снять «недоступен» И закрыть все active delegations этого
 *        менеджера (acting'и больше не видят его заявки).
 *  - delegateActiveRequests(User $unavailable, User $by)
 *      — открыть active-заявкам недоступного менеджера временный доступ
 *        для коллег. Round-robin по available() менеджерам.
 *
 * AssignmentService уже фильтрует по `available()` — недоступный
 * менеджер не получает НОВЫХ заявок. Delegation — отдельный механизм
 * для уже существующих open-заявок.
 */
class ManagerUnavailabilityService
{
    public function __construct(
        private readonly AssignmentService $assignment,
    ) {
    }

    /**
     * Пометить менеджера «недоступен».
     *
     * @param  ?Carbon  $from  начало периода. NULL = «прямо сейчас» (с now()).
     *                        В будущем — планирование, до этого момента
     *                        менеджер ещё в available().
     * @param  bool  $autoDelegate  Открыть активные заявки коллегам автоматически
     *                              в момент начала отсутствия. Применяется
     *                              cron'ом `users:apply-planned-unavailability`
     *                              если from > now(); либо сразу синхронно
     *                              если from <= now() (текущая логика
     *                              UnavailabilityDialog).
     */
    public function markUnavailable(
        User $user,
        ?Carbon $from,
        Carbon $until,
        string $reason,
        ?User $byUser = null,
        bool $autoDelegate = false,
    ): User {
        if (! $user->hasRole(RoleEnum::Manager->value)) {
            throw new \DomainException('Помечать «недоступен» можно только менеджеров.');
        }
        if ($until->isPast()) {
            throw new \DomainException('Дата возврата должна быть в будущем.');
        }
        if ($from !== null && $from->greaterThanOrEqualTo($until)) {
            throw new \DomainException('Начало периода должно быть раньше даты возврата.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new \DomainException('Укажите причину (минимум 3 символа).');
        }

        $user->forceFill([
            'unavailable_from' => $from,
            'unavailable_until' => $until,
            'unavailable_reason' => mb_substr($reason, 0, 500),
            'unavailable_auto_delegate' => $autoDelegate,
        ])->save();

        Log::info('ManagerUnavailabilityService: marked unavailable', [
            'user_id' => $user->id,
            'from' => $from?->toIso8601String(),
            'until' => $until->toIso8601String(),
            'planned' => $from !== null && $from->isFuture(),
            'auto_delegate' => $autoDelegate,
            'reason' => $reason,
            'by' => $byUser?->id,
        ]);

        return $user;
    }

    /**
     * Снять «недоступен» + закрыть active delegations.
     * Коллеги-acting'и больше не видят заявок этого менеджера.
     */
    public function markAvailable(User $user, ?User $byUser = null): User
    {
        $closedCount = 0;
        DB::transaction(function () use ($user, &$closedCount) {
            $user->forceFill([
                'unavailable_from' => null,
                'unavailable_until' => null,
                'unavailable_reason' => null,
                'unavailable_auto_delegate' => false,
            ])->save();

            // Закрываем все active delegations где original_user_id = $user.
            // Коллеги-acting'и больше не увидят эти заявки в своих Pool.
            $closedCount = RequestDelegation::query()
                ->where('original_user_id', $user->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => now()]);
        });

        Log::info('ManagerUnavailabilityService: marked available', [
            'user_id' => $user->id,
            'closed_delegations' => $closedCount,
            'by' => $byUser?->id,
        ]);

        return $user;
    }

    /**
     * Открыть active-заявкам $unavailable временный доступ для коллег.
     *
     * Семантика: заявка ОСТАЁТСЯ за $unavailable (assigned_user_id не
     * меняется), но создаётся `request_delegations` row на коллегу-acting'а.
     * Round-robin по доступным менеджерам (sticky-резолвер тоже задействован
     * — если у кого-то уже есть похожие позиции, ему быстрее войти в курс).
     *
     * Только active-статусы (isOpenForAssignment). Paused / closed / pending
     * не делегируем — там либо менеджер заморозил, либо терминал, либо
     * парсинг ещё не отработал.
     *
     * @return array{delegated: int, skipped: int}
     */
    public function delegateActiveRequests(User $unavailable, ?User $byUser = null): array
    {
        $delegated = 0;
        $skipped = 0;

        // Доступные менеджеры (без самого недоступного) для round-robin.
        $available = User::role(RoleEnum::Manager->value)
            ->available()
            ->where('id', '!=', $unavailable->id)
            ->get();

        if ($available->isEmpty()) {
            Log::warning('ManagerUnavailabilityService: no available managers to delegate to', [
                'unavailable_user_id' => $unavailable->id,
            ]);

            return ['delegated' => 0, 'skipped' => 0];
        }

        $reasonText = sprintf(
            'Отсутствие %s (%s) до %s',
            $unavailable->name,
            $unavailable->unavailable_reason ?: 'нет причины',
            $unavailable->unavailable_until?->format('d.m.Y') ?: '—',
        );

        // Простой round-robin: считаем сколько delegations уже досталось
        // каждому acting'у, отдаём наименее загруженному.
        Request::query()
            ->where('assigned_user_id', $unavailable->id)
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($unavailable, $byUser, $available, $reasonText, &$delegated, &$skipped) {
                foreach ($chunk as $req) {
                    if (! $req->status->isOpenForAssignment()) {
                        $skipped++;
                        continue;
                    }
                    // Идемпотентность: если у заявки уже есть active delegation —
                    // не плодим дублей.
                    $alreadyActive = RequestDelegation::query()
                        ->where('request_id', $req->id)
                        ->whereNull('ended_at')
                        ->exists();
                    if ($alreadyActive) {
                        $skipped++;
                        continue;
                    }

                    // Round-robin: считаем active delegations per acting в этом
                    // батче, отдаём кому меньше.
                    $loadByActing = RequestDelegation::query()
                        ->whereIn('acting_user_id', $available->pluck('id'))
                        ->whereNull('ended_at')
                        ->selectRaw('acting_user_id, COUNT(*) as c')
                        ->groupBy('acting_user_id')
                        ->pluck('c', 'acting_user_id');

                    $actingId = $available
                        ->sortBy(fn ($u) => (int) ($loadByActing[$u->id] ?? 0))
                        ->first()
                        ->id;

                    DB::transaction(function () use ($req, $unavailable, $actingId, $byUser, $reasonText) {
                        RequestDelegation::create([
                            'request_id' => $req->id,
                            'original_user_id' => $unavailable->id,
                            'acting_user_id' => $actingId,
                            'started_at' => now(),
                            'reason' => $reasonText,
                        ]);

                        // Audit в state_changes (от текущего статуса в тот же
                        // — фиксируем факт делегации в общей timeline).
                        RequestStateChange::create([
                            'request_id' => $req->id,
                            'from_status' => $req->status->value,
                            'to_status' => $req->status->value,
                            'by_user_id' => $byUser?->id,
                            'event' => 'delegated_during_absence',
                            'comment' => $reasonText,
                            'payload' => [
                                'original_user_id' => $unavailable->id,
                                'acting_user_id' => $actingId,
                            ],
                        ]);
                    });

                    $delegated++;
                }
            });

        Log::info('ManagerUnavailabilityService: batch delegate done', [
            'from_user_id' => $unavailable->id,
            'delegated' => $delegated,
            'skipped' => $skipped,
            'by' => $byUser?->id,
        ]);

        return ['delegated' => $delegated, 'skipped' => $skipped];
    }
}
