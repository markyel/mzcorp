<?php

namespace App\Services\Request;

use App\Models\Request;
use Carbon\Carbon;

/**
 * Поднимает `requests.last_activity_at` — denormalized timestamp,
 * по которому Pool сортирует «свежие сверху» при прочих равных.
 *
 * Зовётся из:
 *   - IncomingMailProcessor::processIfRequest (новая заявка)
 *   - InboundReplyLinker::tryLink (входящее прицеплено)
 *   - OutgoingMailLinker::tryLink (исходящее прицеплено)
 *   - RequestStateService::transitionTo
 *   - RequestPauseService::pauseUntil / resume
 *   - AssignmentService::autoAssign / делегация
 *   - RequestItemEditor (любая ручная правка позиций)
 *   - AttentionService::setManual / clearManual
 *
 * Использует update'ы напрямую без save() — чтобы не задевать observers
 * и не плодить лишние state-changes.
 */
class RequestActivityService
{
    public function touch(Request|int $request, ?Carbon $at = null): void
    {
        $id = $request instanceof Request ? $request->id : (int) $request;
        $at ??= now();

        Request::query()
            ->whereKey($id)
            ->update(['last_activity_at' => $at]);

        if ($request instanceof Request) {
            $request->last_activity_at = $at;
        }
    }
}
