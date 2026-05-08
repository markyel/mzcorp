<?php

namespace App\Services\Request;

use App\Enums\Role as RoleEnum;
use App\Enums\RequestStatus;
use App\Models\Request;
use App\Models\RequestAssignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 sticky + round-robin.
 *
 * Порядок выбора менеджера:
 *  1) Sticky by item — если хоть одна позиция новой заявки совпадает по
 *     `parsed_article` (TRIM) ИЛИ нормализованному `parsed_name`
 *     (LOWER+TRIM) с позицией другой ОТКРЫТОЙ заявки (status in (new,
 *     assigned)) с уже назначенным менеджером — отдаём её тому менеджеру,
 *     у которого больше всего матчей. Tiebreak — самая свежая Request.
 *     Sticky всегда побеждает балансировку (per оператор).
 *  2) Round-robin — наименее загруженный менеджер (count of new+assigned).
 *     При равенстве — у кого assigned_at давнее.
 *
 * Полный sticky-алгоритм по catalog_item_id (когда появится каталог) —
 * Phase 2 (Foundation §3). Сейчас матчим по сырым parsed_*-полям.
 */
class AssignmentService
{
    /**
     * @return User|null  null если в системе нет активных менеджеров.
     */
    public function autoAssign(Request $request, ?int $byUserId = null): ?User
    {
        // Round-robin и sticky работают только по активным менеджерам;
        // архивированные исключаются из распределения (Phase 1.13).
        $managers = User::role(RoleEnum::Manager->value)->active()->get();
        if ($managers->isEmpty()) {
            return null;
        }

        $manager = $this->pickStickyManager($request, $managers);
        $reason = 'auto_sticky';

        if (! $manager) {
            $manager = $this->pickLeastLoadedManager($managers);
            $reason = 'auto_round_robin';
        }

        if (! $manager) {
            return null;
        }

        DB::transaction(function () use ($request, $manager, $byUserId, $reason) {
            $request->assigned_user_id = $manager->id;
            $request->status = RequestStatus::Assigned;
            $request->assigned_at = now();
            $request->save();

            RequestAssignment::create([
                'request_id' => $request->id,
                'user_id' => $manager->id,
                'by_user_id' => $byUserId,
                'reason' => $reason,
                'assigned_at' => now(),
            ]);
        });

        return $manager;
    }

    /**
     * Sticky-маршрутизация: ищем менеджера, у которого в открытых заявках
     * уже есть позиции с тем же артикулом или именем что и в новой заявке.
     *
     * @param  Collection<int, User>  $managers  Активные менеджеры.
     */
    private function pickStickyManager(Request $request, Collection $managers): ?User
    {
        $items = $request->items()->get(['parsed_article', 'parsed_name']);
        if ($items->isEmpty()) {
            return null;
        }

        $articles = $items->pluck('parsed_article')
            ->map(fn ($a) => trim((string) $a))
            ->filter(fn ($a) => $a !== '')
            ->unique()
            ->values()
            ->all();

        $names = $items->pluck('parsed_name')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))
            ->filter(fn ($n) => $n !== '')
            ->unique()
            ->values()
            ->all();

        if (empty($articles) && empty($names)) {
            return null;
        }

        $row = DB::table('request_items')
            ->join('requests', 'request_items.request_id', '=', 'requests.id')
            ->whereIn('requests.assigned_user_id', $managers->pluck('id')->all())
            ->where('requests.id', '!=', $request->id)
            ->whereIn('requests.status', [
                RequestStatus::New->value,
                RequestStatus::Assigned->value,
            ])
            ->where(function ($q) use ($articles, $names) {
                if (! empty($articles)) {
                    $q->orWhereIn(DB::raw('TRIM(request_items.parsed_article)'), $articles);
                }
                if (! empty($names)) {
                    $q->orWhereIn(DB::raw('LOWER(TRIM(request_items.parsed_name))'), $names);
                }
            })
            ->groupBy('requests.assigned_user_id')
            ->selectRaw('requests.assigned_user_id, COUNT(*) AS hits, MAX(requests.created_at) AS latest_created')
            ->orderByDesc('hits')
            ->orderByDesc('latest_created')
            ->first();

        if (! $row) {
            return null;
        }

        return $managers->firstWhere('id', (int) $row->assigned_user_id);
    }

    /**
     * Менеджер с наименьшим текущим load (count of assigned requests).
     * При равенстве — у кого assigned_at последней заявки давнее.
     *
     * @param  Collection<int, User>  $managers  Активные менеджеры.
     */
    private function pickLeastLoadedManager(Collection $managers): ?User
    {
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
