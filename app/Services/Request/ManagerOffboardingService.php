<?php

namespace App\Services\Request;

use App\Enums\RequestStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Request;
use App\Models\RequestDelegation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Менеджер уходит насовсем: его незакрытые заявки переходят другим, его
 * делегирования закрываются, учётка уходит в архив (вход и личные ящики
 * отключает UserObserver).
 *
 * Раньше «Архивировать» только ставил archived_at: заявки оставались за
 * ушедшим менеджером («переподчините вручную»), ответы клиентов по ним
 * приходили в пустоту, а в «Заброшенных» пула их не было видно.
 *
 * Отличие от отсутствия (ManagerUnavailabilityService): там заявки остаются
 * за менеджером и коллеги получают временный доступ; здесь меняется
 * ответственный — через ReassignService, с записью в request_assignments.
 *
 * Наследник — один выбранный менеджер или распределение между доступными:
 * заявки одного клиента (e-mail) уходят одному наследнику, группы — тому, у
 * кого меньше (нагрузка + группа) / load_weight, с учётом потолка сложности
 * (ManagerComplexityGate) — как в обычном распределении.
 */
class ManagerOffboardingService
{
    public function __construct(
        private readonly ReassignService $reassign,
        private readonly ManagerComplexityGate $complexityGate,
        private readonly ManagerUnavailabilityService $unavailability,
    ) {}

    /** Незакрытые заявки менеджера — все, кроме успешно/неуспешно закрытых. */
    public function openRequests(User $leaver): Collection
    {
        return Request::query()
            ->where('assigned_user_id', $leaver->id)
            ->whereNull('merged_into_id')
            ->whereNotIn('status', [RequestStatus::ClosedWon->value, RequestStatus::ClosedLost->value])
            ->orderBy('id')
            ->get();
    }

    /** Кому можно передать: доступные менеджеры/РОП, кроме уходящего. */
    public function candidates(User $leaver): Collection
    {
        return User::role(RoleEnum::requestHandlerRoles())
            ->available()
            ->where('id', '!=', $leaver->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * План передачи: request_id → наследник.
     *
     * @return array{assign: array<int, User>, by_heir: array<int, array{user: User, requests: int, clients: int}>, requests: int, clients: int}
     */
    public function plan(User $leaver, ?User $heir = null): array
    {
        $requests = $this->openRequests($leaver);
        $groups = $requests->groupBy(fn (Request $r) => ($e = mb_strtolower(trim((string) $r->client_email))) !== '' ? $e : 'req:'.$r->id)
            ->sortByDesc(fn (Collection $g) => $g->count());

        $candidates = $heir !== null ? collect([$heir]) : $this->candidates($leaver);
        $assign = [];
        $byHeir = [];
        if ($candidates->isEmpty()) {
            return ['assign' => [], 'by_heir' => [], 'requests' => $requests->count(), 'clients' => $groups->count()];
        }

        // Текущая нагрузка — незакрытые заявки в работе (как в распределении).
        $openStatuses = array_map(
            fn (RequestStatus $s) => $s->value,
            array_filter(RequestStatus::cases(), fn (RequestStatus $s) => $s->isOpenForAssignment()),
        );
        $load = Request::query()
            ->whereIn('assigned_user_id', $candidates->pluck('id'))
            ->whereIn('status', $openStatuses)
            ->groupBy('assigned_user_id')
            ->selectRaw('assigned_user_id, COUNT(*) AS c')
            ->pluck('c', 'assigned_user_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        foreach ($groups as $group) {
            $pool = $candidates;
            if ($heir === null) {
                // Потолок сложности: наследник должен потянуть каждую заявку
                // клиента; если не тянет никто — фильтр снимаем.
                $fit = $candidates->filter(fn (User $u) => $group->every(fn (Request $r) => $this->complexityGate->canTake($u, $r)));
                $pool = $fit->isNotEmpty() ? $fit : $candidates;
            }
            $size = $group->count();
            $picked = $pool->sortBy(fn (User $u) => [
                (($load[$u->id] ?? 0) + $size) / max(1, min(500, (int) ($u->load_weight ?? 100))),
                $u->id,
            ])->first();

            $load[$picked->id] = ($load[$picked->id] ?? 0) + $size;
            $byHeir[$picked->id] ??= ['user' => $picked, 'requests' => 0, 'clients' => 0];
            $byHeir[$picked->id]['requests'] += $size;
            $byHeir[$picked->id]['clients']++;
            foreach ($group as $r) {
                $assign[$r->id] = $picked;
            }
        }

        uasort($byHeir, fn ($a, $b) => $b['requests'] <=> $a['requests']);

        return ['assign' => $assign, 'by_heir' => $byHeir, 'requests' => $requests->count(), 'clients' => $groups->count()];
    }

    /**
     * Делегирования, которые закроются: где уходящий отсутствующий и где он
     * замещающий.
     *
     * @return array{as_original: int, as_acting: int}
     */
    public function delegations(User $leaver): array
    {
        return [
            'as_original' => RequestDelegation::query()->whereNull('ended_at')->where('original_user_id', $leaver->id)->count(),
            'as_acting' => RequestDelegation::query()->whereNull('ended_at')->where('acting_user_id', $leaver->id)->count(),
        ];
    }

    /**
     * Отключить менеджера.
     *
     * @return array{reassigned: int, failed: int, delegations_closed: int, redelegated: int}
     */
    public function offboard(User $leaver, ?User $heir, User $by, ?string $comment = null): array
    {
        $plan = $this->plan($leaver, $heir);
        $requests = $this->openRequests($leaver)->keyBy('id');
        $reason = trim('Менеджер '.$leaver->name.' отключён'.($comment ? ': '.$comment : ''));

        $reassigned = 0;
        $failed = 0;
        foreach ($plan['assign'] as $requestId => $newAssignee) {
            $request = $requests[$requestId] ?? null;
            if ($request === null) {
                continue;
            }
            try {
                $this->reassign->reassign($request, $newAssignee, $reason, $by);
                $reassigned++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('ManagerOffboarding: reassign failed', [
                    'request_id' => $requestId, 'leaver_id' => $leaver->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        $closed = 0;
        $redelegated = 0;
        DB::transaction(function () use ($leaver, &$closed) {
            // Заявки теперь у наследника — временный доступ коллег не нужен.
            $closed += RequestDelegation::query()->whereNull('ended_at')->where('original_user_id', $leaver->id)
                ->update(['ended_at' => now()]);

            $leaver->forceFill([
                'archived_at' => now(),
                'unavailable_from' => null,
                'unavailable_until' => null,
                'unavailable_reason' => null,
                'unavailable_auto_delegate' => false,
            ])->save();
        });

        // Уходящий замещал отсутствующих коллег — их заявки снова открываем
        // другому доступному (архивный в available() уже не попадёт).
        $acting = RequestDelegation::query()->whereNull('ended_at')->where('acting_user_id', $leaver->id)->get();
        foreach ($acting as $delegation) {
            $delegation->forceFill(['ended_at' => now()])->save();
            $closed++;
            $request = Request::query()->find($delegation->request_id);
            $original = User::query()->find($delegation->original_user_id);
            if ($request !== null && $original !== null && $original->isUnavailable()
                && (int) $request->assigned_user_id === (int) $original->id) {
                try {
                    if ($this->unavailability->delegateOne($request, $original, $by) !== null) {
                        $redelegated++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('ManagerOffboarding: re-delegation failed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
                }
            }
        }

        Log::info('ManagerOffboarding: manager disabled', [
            'leaver_id' => $leaver->id, 'by' => $by->id, 'heir_id' => $heir?->id,
            'reassigned' => $reassigned, 'failed' => $failed, 'delegations_closed' => $closed, 'redelegated' => $redelegated,
        ]);

        return ['reassigned' => $reassigned, 'failed' => $failed, 'delegations_closed' => $closed, 'redelegated' => $redelegated];
    }
}
