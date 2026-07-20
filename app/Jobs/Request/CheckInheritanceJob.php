<?php

namespace App\Jobs\Request;

use App\Enums\ClosedLostReason;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request as RequestModel;
use App\Services\Request\InheritanceCandidateChecker;
use App\Services\Request\RequestInheritanceService;
use App\Services\Request\RequestStateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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
        RequestStateService $stateService,
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

        // ГАРД «это постпродажа, не новая заявка»: клиент ответил в закрытый
        // тред письмом про документооборот/отгрузку (реквизиты, УПД, закрывающие,
        // комплектация) И у него есть недавний оплаченный/выигранный заказ →
        // переписка уходит на этот заказ, свежесозданная заявка сворачивается.
        // Кейс M-2026-7877: реквизиты для документов по оплаченной M-2026-6184
        // прилетели ответом в тред давно проигранной M-2026-4581 — inheritance
        // создал фантомную заявку-наследника.
        if ($this->rerouteAsPostSale($newRequest, $inboundMessage)) {
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

        // LLM подтвердил гипотезу-продолжение. ГИБРИД:
        //   - позиции РОВНО те же (все позиции новой совпали по АРТИКУЛУ со
        //     старой) И старая закрыта недавно «по нет ответа» → РЕАНИМИРУЕМ
        //     старую под тем же номером, свежесозданную новую сворачиваем;
        //   - иначе → обычное наследование (новая = child закрытой).
        $itemMappings = $inheritance->suggestLinks($candidate, $newRequest);

        if ($this->shouldReanimateInPlace($candidate, $newRequest, $itemMappings)) {
            try {
                $this->reanimateInPlace($candidate, $newRequest, $inboundMessage, $stateService, $result);

                return;
            } catch (\Throwable $e) {
                // Реанимация+свёртка не удалась (напр. FK) — откат транзакции
                // внутри, данные целы. Падаем в безопасное наследование ниже.
                Log::error('CheckInheritanceJob: reanimate-in-place failed, falling back to linkChild', [
                    'new_request_id' => $newRequest->id,
                    'candidate_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
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

    /**
     * Перехват постпродажи: если письмо-триггер — документооборот/отгрузка по
     * состоявшейся сделке и у клиента есть недавний оплаченный (paid) или
     * выигранный (closed_won) заказ, то:
     *   1) вся переписка свежесозданной заявки перевешивается на этот заказ
     *      (входящие получают category=post_sale);
     *   2) на заказе поднимается attention «🛒 Постпродажное сообщение»;
     *   3) свежесозданная заявка удаляется (как в reanimateInPlace).
     * Возвращает true, если перехват сработал (наследование не нужно).
     */
    private function rerouteAsPostSale(RequestModel $newRequest, EmailMessage $inbound): bool
    {
        try {
            $signal = app(\App\Services\Mail\PostSaleFulfillmentDetector::class)
                ->detectDocsOrFulfillment($inbound);
            if ($signal === null) {
                return false;
            }

            $clientEmail = trim((string) $newRequest->client_email);
            if ($clientEmail === '') {
                return false;
            }
            $maxDays = (int) config('services.inheritance.post_sale_reroute_max_days', 60);
            $order = RequestModel::query()
                ->where('client_email', $clientEmail)
                ->where('id', '!=', $newRequest->id)
                ->whereIn('status', [RequestStatus::Paid->value, RequestStatus::ClosedWon->value])
                ->where('updated_at', '>', now()->subDays(max(1, $maxDays)))
                ->orderByDesc('id')
                ->first();
            if ($order === null) {
                return false;
            }

            $newId = $newRequest->id;
            $newCode = $newRequest->internal_code;

            DB::transaction(function () use ($newRequest, $order) {
                $moved = EmailMessage::query()
                    ->where('related_request_id', $newRequest->id)
                    ->get();
                foreach ($moved as $m) {
                    $upd = ['related_request_id' => $order->id];
                    if ($m->direction === \App\Enums\MailDirection::Inbound) {
                        $upd['category'] = \App\Enums\EmailCategory::PostSale->value;
                    }
                    $m->forceFill($upd)->save();
                }
                DB::table('client_notifications_sent')->where('request_id', $newRequest->id)->delete();
                $newRequest->delete();
            });

            try {
                app(\App\Services\Request\AttentionService::class)->onPostSaleMessage($order->fresh());
            } catch (\Throwable) {
                // attention — best-effort
            }

            Log::info('CheckInheritanceJob: rerouted as post_sale, absorbed fresh request', [
                'deleted_new_request_id' => $newId,
                'deleted_new_code' => $newCode,
                'order_request_id' => $order->id,
                'order_code' => $order->internal_code,
                'signal' => $signal,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('CheckInheritanceJob: post_sale reroute failed, continuing with inheritance', [
                'new_request_id' => $newRequest->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Гибрид-условие: реанимировать СТАРУЮ (переоткрыть под тем же номером)
     * вместо создания связанной новой. Требуем МАКСИМУМ уверенности, т.к.
     * ветка деструктивная (удаляет свежесозданную новую заявку):
     *   - старая именно closed_lost (closed_won = сделка состоялась → новая ок);
     *   - причина закрытия — «нет ответа» (тихое закрытие), в окне N дней;
     *   - новой номенклатуры нет: либо у новой заявки ВСЕ активные позиции
     *     совпали по АРТИКУЛУ (source auto_article) со старой, либо позиций
     *     нет вовсе (ответ-текст в старый тред, клиент ничего не добавил);
     *   - у новой ещё нет downstream-артефактов (КП/счёт) — она свежая.
     *
     * @param  array<int, array{child_item_id:int, parent_item_id:int, source?:string, confidence?:float}>  $itemMappings
     */
    private function shouldReanimateInPlace(RequestModel $candidate, RequestModel $newRequest, array $itemMappings): bool
    {
        if ($candidate->status !== RequestStatus::ClosedLost) {
            return false;
        }

        $noResponse = in_array($candidate->closed_lost_reason, [
            ClosedLostReason::NoClientResponseToQuote->value,
            ClosedLostReason::NoClientResponseToClarification->value,
        ], true);
        $maxDays = (int) config('services.inheritance.reanimate_max_days', 45);
        $recent = $candidate->closed_at !== null
            && $candidate->closed_at->gt(now()->subDays(max(1, $maxDays)));
        if (! $noResponse || ! $recent) {
            return false;
        }

        // Ровно те же позиции: каждая активная позиция новой совпала по артикулу.
        //
        // НОЛЬ позиций — тоже основание реанимировать, а не блокировать. Клиент
        // просто написал текст в старый тред («когда отгрузите?», «аварийная
        // ситуация, нужно быстрее»), новой номенклатуры нет вообще — это
        // сильнейший сигнал продолжения. Раньше здесь стоял `return false`, и
        // такой ответ плодил дубль-наследника с клонированными позициями, уводя
        // живой диалог с исходной заявки: кейс M-2026-7976 → M-2026-8874 (ответ
        // на НАШЕ же напоминание о КП, через 3 часа после авто-закрытия «нет
        // ответа»; КП 360908 и номер 1С остались на старой, переписка ушла на новую).
        // Продолжение к этому моменту подтверждено дважды: тред сматчен по
        // in_reply_to к терминальной заявке (жёсткий заголовочный матч) + LLM
        // is_continuation ≥ threshold в handle(). Пост-продажный кейс отсечён
        // раньше в rerouteAsPostSale().
        $newActive = (int) $newRequest->items()->where('is_active', true)->count();
        if ($newActive > 0) {
            $exactArticleMapped = collect($itemMappings)
                ->where('source', 'auto_article')
                ->pluck('child_item_id')
                ->unique()
                ->count();
            if ($exactArticleMapped !== $newActive) {
                return false;
            }
        }

        // Новая заявка ещё «пустая» downstream — иначе не сворачиваем.
        $hasArtifacts = DB::table('quotations')->where('request_id', $newRequest->id)->exists()
            || DB::table('invoices')->where('request_id', $newRequest->id)->exists()
            || DB::table('outbound_quotes')->where('request_id', $newRequest->id)->exists();

        return ! $hasArtifacts;
    }

    /**
     * Реанимировать старую заявку под тем же номером и свернуть свежесозданную
     * новую: переносим её письма на старую, реанимируем старую, удаляем новую
     * (каскад чистит её items/state/assignments/views/ai_decisions). Всё в одной
     * транзакции — атомарно.
     *
     * @param  array{is_continuation: bool, confidence: float, reasoning: ?string}  $llmResult
     */
    private function reanimateInPlace(
        RequestModel $candidate,
        RequestModel $newRequest,
        EmailMessage $inbound,
        RequestStateService $stateService,
        array $llmResult,
    ): void {
        $newCode = $newRequest->internal_code;

        // Сама операция — общая с zero-items путём в ParseRequestItemsJob
        // (см. RequestInheritanceService::reanimateParentAbsorbingChild).
        app(\App\Services\Request\RequestInheritanceService::class)
            ->reanimateParentAbsorbingChild(
                $candidate,
                $newRequest,
                $inbound,
                event: 'reanimate_from_reply',
                comment: "Клиент ответил в закрытый тред теми же позициями — реанимация (свёрнут дубль {$newCode}).",
            );

        Log::info('CheckInheritanceJob: reanimated parent in-place, absorbed fresh duplicate', [
            'parent_request_id' => $candidate->id,
            'parent_code' => $candidate->internal_code,
            'confidence' => $llmResult['confidence'] ?? null,
        ]);
    }
}
