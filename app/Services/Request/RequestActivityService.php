<?php

namespace App\Services\Request;

use App\Enums\RequestActivityType;
use App\Models\Request;
use Carbon\Carbon;

/**
 * Поднимает `requests.last_activity_at` + (опционально) тип события в
 * `last_activity_type`. По нему Pool сортирует «свежие сверху» и рисует
 * колонку «Событие».
 *
 * Если переданный $type силенсит attention (clarification_sent /
 * quote_sent / invoice_sent / manager_replied / supplier_inquiry_sent),
 * автоматически снимаем существующий info-flag (ClientReplied /
 * FreshAssignment / SupplierReplied) через AttentionService::onManagerHandled.
 *
 * Зовётся из:
 *   - IncomingMailProcessor::processIfRequest  → RequestCreated
 *   - AssignmentService::autoAssign            → Assigned
 *   - InboundReplyLinker::tryLink              → ClientReplied (+sent_at)
 *   - OutgoingMailLinker::tryLink              → ManagerReplied (+sent_at)
 *   - RequestStateService::transitionTo        → выбор по to_status
 *   - RequestStateService::reanimate           → Reanimated
 *   - RequestPauseService::pauseUntil          → Paused
 *   - RequestPauseService::resume              → Resumed
 *   - AttentionService::setManual              → ManualFlagSet
 *   - AttentionService::clearManual            → ManualFlagCleared
 *   - RequestItemObserver                      → null (только timestamp)
 */
class RequestActivityService
{
    public function __construct(
        private readonly AttentionService $attention,
    ) {
    }

    public function touch(Request|int $request, ?RequestActivityType $type = null, ?Carbon $at = null): void
    {
        $id = $request instanceof Request ? $request->id : (int) $request;
        $at ??= now();

        $payload = ['last_activity_at' => $at];
        if ($type !== null) {
            $payload['last_activity_type'] = $type->value;
        }

        Request::query()->whereKey($id)->update($payload);

        if ($request instanceof Request) {
            $request->last_activity_at = $at;
            if ($type !== null) {
                $request->last_activity_type = $type->value;
            }
        }

        // Auto-silence: «ход передан» → снимаем info-flag, если стоял.
        if ($type !== null && $type->silencesAttention()) {
            $fresh = $request instanceof Request ? $request->fresh() : Request::find($id);
            if ($fresh !== null) {
                $this->attention->onManagerHandled($fresh);
            }
        }
    }
}
