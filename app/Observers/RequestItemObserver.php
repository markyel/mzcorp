<?php

namespace App\Observers;

use App\Models\Request;
use App\Models\RequestItem;
use App\Services\Request\RequestActivityService;
use App\Services\Request\RequestComplexityService;

/**
 * Любое изменение позиции (создание / правка / soft-delete):
 *   1. Touch'ит родительскую заявку (`requests.last_activity_at`)
 *      — поднимает её в Pool. Альтернатива — touch'ить из каждого
 *      метода RequestItemEditor (~12 точек) и из RequestItemPersister.
 *
 *   2. Обновляет `request_items.match_path` (snapshot входной сложности)
 *      и пересчитывает `requests.complexity_score` + `complexity_level`.
 *      Цепочка: payload.catalog_match.method → MatchPath::detect →
 *      complexity_score = Σ weights.
 */
class RequestItemObserver
{
    public function __construct(
        private readonly RequestActivityService $activity,
        private readonly RequestComplexityService $complexity,
    ) {
    }

    public function created(RequestItem $item): void
    {
        $this->complexity->detectAndStoreItemPath($item);
        $this->touchParent($item);
        $this->recomputeComplexity($item);
    }

    public function updated(RequestItem $item): void
    {
        // Пересчёт match_path при изменении payload / catalog_item_id.
        // detectAndStoreItemPath делает UPDATE через Query Builder,
        // чтобы не зациклить observer.
        $this->complexity->detectAndStoreItemPath($item);
        $this->touchParent($item);
        $this->recomputeComplexity($item);
    }

    public function deleted(RequestItem $item): void
    {
        $this->touchParent($item);
        $this->recomputeComplexity($item);
    }

    private function touchParent(RequestItem $item): void
    {
        if ($item->request_id) {
            $this->activity->touch((int) $item->request_id);
        }
    }

    private function recomputeComplexity(RequestItem $item): void
    {
        if (! $item->request_id) {
            return;
        }
        $request = Request::find($item->request_id);
        if ($request) {
            $this->complexity->recompute($request);
        }
    }
}
