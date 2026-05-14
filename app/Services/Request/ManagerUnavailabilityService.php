<?php

namespace App\Services\Request;

use App\Enums\Role as RoleEnum;
use App\Models\Request;
use App\Models\RequestAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Менеджер «недоступен» — отпуск / командировка / больничный
 * (Foundation Фаза 2).
 *
 * 3 operation:
 *  - markUnavailable(User, Carbon $until, string $reason, User $by)
 *      — выставить `unavailable_until` + `unavailable_reason`.
 *  - markAvailable(User, User $by)
 *      — снять «недоступен» немедленно (вернулся раньше).
 *  - reassignActiveRequests(User $unavailable, User $by)
 *      — массово переподчинить все open-заявки этого менеджера через
 *        AssignmentService::autoAssign (round-robin + sticky). Sticky
 *        не сматчится на самого недоступного (он выбит из available()).
 *
 * Phase 1.13 чекаут: `AssignmentService` уже сейчас фильтрует по
 * `available()`, так что просто пометить «недоступен» — достаточно для
 * новых заявок. Reassign — отдельная явная операция РОПа: пометил
 * «отпуск 14 дней» → нажал «передать все заявки коллегам».
 */
class ManagerUnavailabilityService
{
    public function __construct(
        private readonly AssignmentService $assignment,
    ) {
    }

    /**
     * @return User обновлённый user
     */
    public function markUnavailable(
        User $user,
        Carbon $until,
        string $reason,
        ?User $byUser = null,
    ): User {
        if (! $user->hasRole(RoleEnum::Manager->value)) {
            throw new \DomainException('Помечать «недоступен» можно только менеджеров.');
        }
        if ($until->isPast()) {
            throw new \DomainException('Дата возврата должна быть в будущем.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new \DomainException('Укажите причину (минимум 3 символа).');
        }

        $user->forceFill([
            'unavailable_until' => $until,
            'unavailable_reason' => mb_substr($reason, 0, 500),
        ])->save();

        Log::info('ManagerUnavailabilityService: marked unavailable', [
            'user_id' => $user->id,
            'until' => $until->toIso8601String(),
            'reason' => $reason,
            'by' => $byUser?->id,
        ]);

        return $user;
    }

    public function markAvailable(User $user, ?User $byUser = null): User
    {
        $user->forceFill([
            'unavailable_until' => null,
            'unavailable_reason' => null,
        ])->save();

        Log::info('ManagerUnavailabilityService: marked available', [
            'user_id' => $user->id,
            'by' => $byUser?->id,
        ]);

        return $user;
    }

    /**
     * Массово переподчинить open-заявки от $unavailable другим менеджерам.
     * Использует AssignmentService::autoAssign (sticky + round-robin), что
     * даёт реалистичное распределение (а не «всё одному», как тупой fallback).
     *
     * Только active-статусы (isOpenForAssignment). Paused / closed / pending
     * не трогаем — там либо menager заморозил, либо терминал, либо парсинг
     * ещё не отработал.
     *
     * @return array{reassigned: int, skipped: int}
     */
    public function reassignActiveRequests(User $unavailable, ?User $byUser = null): array
    {
        $reassigned = 0;
        $skipped = 0;

        Request::query()
            ->where('assigned_user_id', $unavailable->id)
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($unavailable, $byUser, &$reassigned, &$skipped) {
                foreach ($chunk as $req) {
                    if (! $req->status->isOpenForAssignment()) {
                        $skipped++;
                        continue;
                    }
                    DB::transaction(function () use ($req, $unavailable, $byUser, &$reassigned, &$skipped) {
                        // Отвязываем заявку, чтобы autoAssign отработал «как с нуля».
                        $req->assigned_user_id = null;
                        $req->save();

                        $newManager = $this->assignment->autoAssign($req->fresh(), $byUser?->id);
                        if ($newManager !== null && $newManager->id !== $unavailable->id) {
                            // Audit-причина — отдельная для batch-reassign.
                            RequestAssignment::query()
                                ->where('request_id', $req->id)
                                ->latest('id')
                                ->limit(1)
                                ->update(['reason' => 'reassign_from_unavailable']);
                            $reassigned++;
                        } else {
                            // autoAssign не нашёл другого менеджера (один сотрудник
                            // в системе или все недоступны). Возвращаем как было —
                            // оператор-РОП ещё разберётся.
                            $req->assigned_user_id = $unavailable->id;
                            $req->save();
                            $skipped++;
                        }
                    });
                }
            });

        Log::info('ManagerUnavailabilityService: batch reassign done', [
            'from_user_id' => $unavailable->id,
            'reassigned' => $reassigned,
            'skipped' => $skipped,
            'by' => $byUser?->id,
        ]);

        return ['reassigned' => $reassigned, 'skipped' => $skipped];
    }
}
