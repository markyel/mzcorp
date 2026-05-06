<?php

namespace App\Services\Request;

use App\Enums\Role as RoleEnum;
use App\Enums\RequestStatus;
use App\Models\Request;
use App\Models\RequestAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Минимальный Phase 1 round-robin: назначаем заявку наименее загруженному
 * активному менеджеру (по числу активных заявок). При равенстве — тому,
 * кто давно не получал.
 *
 * Полный sticky-алгоритм по catalog_item_id — Phase 2 (Foundation §3).
 */
class AssignmentService
{
    /**
     * @return User|null  null если в системе нет активных менеджеров.
     */
    public function autoAssign(Request $request, ?int $byUserId = null): ?User
    {
        $manager = $this->pickLeastLoadedManager();
        if (! $manager) {
            return null;
        }

        DB::transaction(function () use ($request, $manager, $byUserId) {
            $request->assigned_user_id = $manager->id;
            $request->status = RequestStatus::Assigned;
            $request->assigned_at = now();
            $request->save();

            RequestAssignment::create([
                'request_id' => $request->id,
                'user_id' => $manager->id,
                'by_user_id' => $byUserId,
                'reason' => 'auto_round_robin',
                'assigned_at' => now(),
            ]);
        });

        return $manager;
    }

    /**
     * Менеджер с наименьшим текущим load (count of assigned requests).
     * При равенстве — у кого assigned_at последней заявки давнее.
     */
    private function pickLeastLoadedManager(): ?User
    {
        $managers = User::role(RoleEnum::Manager->value)->get();
        if ($managers->isEmpty()) {
            return null;
        }

        // Подсчёт активных заявок на каждого менеджера.
        $loadByUser = Request::query()
            ->whereIn('assigned_user_id', $managers->pluck('id'))
            ->whereIn('status', [RequestStatus::Assigned->value, RequestStatus::New->value])
            ->groupBy('assigned_user_id')
            ->selectRaw('assigned_user_id, COUNT(*) AS load_count, MAX(assigned_at) AS last_assigned_at')
            ->get()
            ->keyBy('assigned_user_id');

        $candidates = $managers->map(function (User $u) use ($loadByUser) {
            $row = $loadByUser->get($u->id);

            return [
                'user' => $u,
                'load' => (int) ($row->load_count ?? 0),
                'last_assigned_at' => $row->last_assigned_at ?? null,
            ];
        });

        $sorted = $candidates->sort(function ($a, $b) {
            // 1) меньше нагрузка — раньше
            if ($a['load'] !== $b['load']) {
                return $a['load'] <=> $b['load'];
            }
            // 2) кому давнее назначали — раньше (NULL первым)
            if ($a['last_assigned_at'] === null) {
                return -1;
            }
            if ($b['last_assigned_at'] === null) {
                return 1;
            }

            return strcmp($a['last_assigned_at'], $b['last_assigned_at']);
        })->values();

        return $sorted->first()['user'] ?? null;
    }
}
