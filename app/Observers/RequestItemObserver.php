<?php

namespace App\Observers;

use App\Models\RequestItem;
use App\Services\Request\RequestActivityService;

/**
 * Любое изменение позиции (создание / правка / soft-delete) — это
 * активность по родительской заявке, поднимает её в Pool через
 * `requests.last_activity_at`. Альтернатива — touch'ить из каждого
 * метода RequestItemEditor (~12 точек) и из RequestItemPersister.
 */
class RequestItemObserver
{
    public function __construct(
        private readonly RequestActivityService $activity,
    ) {
    }

    public function created(RequestItem $item): void
    {
        $this->touchParent($item);
    }

    public function updated(RequestItem $item): void
    {
        $this->touchParent($item);
    }

    public function deleted(RequestItem $item): void
    {
        $this->touchParent($item);
    }

    private function touchParent(RequestItem $item): void
    {
        if ($item->request_id) {
            $this->activity->touch((int) $item->request_id);
        }
    }
}
