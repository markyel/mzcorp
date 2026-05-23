<?php

namespace App\Jobs\Request;

use App\Models\EmailMessage;
use App\Models\Request as RequestModel;
use App\Services\Request\InheritanceCandidateChecker;
use App\Services\Request\RequestInheritanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2.1 — async проверка гипотезы наследования.
 *
 * Flow:
 *   1. `InboundReplyLinker` при матче на closed_lost — записывает кандидата
 *      в `email_messages.detected_artifacts.inheritance_candidate_id` и
 *      возвращает null (не реанимирует).
 *   2. `IncomingMailProcessor` создаёт новую Request, dispatches
 *      `ParseRequestItemsJob`.
 *   3. `RequestItemPersister` после успешного persist + autoAssign —
 *      dispatches `CheckInheritanceJob(new_request_id)` если у source
 *      email есть inheritance_candidate_id.
 *   4. Этот job: читает candidate, дёргает LLM (`InheritanceCandidateChecker`),
 *      при confidence ≥ threshold — `RequestInheritanceService::linkChild`.
 *
 * Идемпотентность: ShouldBeUnique по new_request_id с окном 10 минут.
 * Повторный dispatch (например, при ручном reparse) не плодит дублей.
 */
class CheckInheritanceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public readonly int $newRequestId)
    {
    }

    public function uniqueId(): string
    {
        return 'check-inheritance-' . $this->newRequestId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(
        InheritanceCandidateChecker $checker,
        RequestInheritanceService $inheritance,
    ): void {
        $newRequest = RequestModel::find($this->newRequestId);
        if (! $newRequest) {
            Log::warning('CheckInheritanceJob: new request not found', [
                'new_request_id' => $this->newRequestId,
            ]);

            return;
        }

        // Уже наследник (например, повторный запуск). Idempotent skip.
        if ($newRequest->inheritance_parent_id !== null) {
            return;
        }

        $inboundMessage = $newRequest->emailMessage;
        if (! $inboundMessage) {
            return;
        }

        $artifacts = (array) ($inboundMessage->detected_artifacts ?? []);
        $candidateId = (int) ($artifacts['inheritance_candidate_id'] ?? 0);
        if ($candidateId <= 0) {
            return;
        }

        $candidate = RequestModel::find($candidateId);
        if (! $candidate) {
            Log::info('CheckInheritanceJob: candidate not found, skip', [
                'new_request_id' => $newRequest->id,
                'candidate_id' => $candidateId,
            ]);

            return;
        }

        // Защита: кандидат должен быть всё ещё закрыт (если успели
        // реанимировать вручную через UI — наследование не нужно).
        if (! $candidate->status->isTerminal()) {
            return;
        }

        $result = $checker->check($inboundMessage, $newRequest, $candidate);
        if ($result === null) {
            return; // LLM сломался, считаем «не подтверждено»
        }

        $threshold = (float) config('services.inheritance.confidence_threshold', 0.7);

        if (! $result['is_continuation'] || $result['confidence'] < $threshold) {
            Log::info('CheckInheritanceJob: not a continuation, leaving as standalone', [
                'new_request_id' => $newRequest->id,
                'candidate_id' => $candidate->id,
                'is_continuation' => $result['is_continuation'],
                'confidence' => $result['confidence'],
                'threshold' => $threshold,
            ]);

            return;
        }

        // LLM подтвердил гипотезу — создаём наследование.
        try {
            $itemMappings = $inheritance->suggestLinks($candidate, $newRequest);
            $inheritance->linkChild(
                parent: $candidate,
                child: $newRequest,
                itemMappings: $itemMappings,
                linkedBy: 'auto_llm',
            );

            Log::info('CheckInheritanceJob: inheritance linked', [
                'parent_request_id' => $candidate->id,
                'parent_code' => $candidate->internal_code,
                'child_request_id' => $newRequest->id,
                'child_code' => $newRequest->internal_code,
                'confidence' => $result['confidence'],
                'item_mappings' => count($itemMappings),
                'reasoning' => $result['reasoning'],
            ]);
        } catch (\Throwable $e) {
            Log::error('CheckInheritanceJob: linkChild failed', [
                'new_request_id' => $newRequest->id,
                'candidate_id' => $candidate->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
