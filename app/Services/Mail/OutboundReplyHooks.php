<?php

namespace App\Services\Mail;

use App\Enums\EmailCategory;
use App\Enums\RequestStatus;
use App\Models\ClarificationBatch;
use App\Models\EmailMessage;
use App\Models\Quotation;
use App\Models\Request;
use App\Models\User;
use App\Services\Quotations\QuotationService;
use App\Services\Request\RequestStateService;
use Illuminate\Support\Facades\Log;

/**
 * Пост-обработка отправленного НАШЕГО письма (гибрид-пайплайн).
 *
 * Вынесено из App\Livewire\Requests\Mail\ComposeForm (историческое место), чтобы
 * тот же гибрид-хук можно было звать из нового почтового клиента
 * (App\Livewire\Mail\Composer) при отправке ответа на письмо, привязанное к
 * заявке. Поведение идентично прежнему; отличие — User передаётся явно (не
 * через auth()), т.к. сервис не привязан к Livewire-контексту.
 *
 * Свободная почта (related_request_id = null) сюда НЕ заходит — вызывающий
 * код вызывает хуки только при наличии заявки.
 */
class OutboundReplyHooks
{
    public function __construct(
        private readonly RequestStateService $stateService,
        private readonly QuotationService $quotationService,
        private readonly MailRouter $mailRouter,
    ) {}

    /**
     * Обработать markers в detected_artifacts отправленного письма:
     * clarification_batch → статус AwaitingClientClarification;
     * quotation_sent → markSent + статус Quoted.
     *
     * @return bool true, если обработан known-marker (КП/уточнение уже сами
     *              сменили статус — send-time детект документов не нужен).
     */
    public function applyPostSendHooks(EmailMessage $sent, User $actor): bool
    {
        $artifacts = is_array($sent->detected_artifacts ?? null) ? $sent->detected_artifacts : [];

        $handledAny = false;
        foreach ($artifacts as $marker) {
            if (! is_array($marker)) {
                continue;
            }
            switch ($marker['type'] ?? null) {
                case 'clarification_batch':
                    $this->handleClarificationBatchHook($sent, $marker, $actor);
                    $handledAny = true;
                    break;
                case 'quotation_sent':
                    $this->handleQuotationSentHook($sent, $marker, $actor);
                    $handledAny = true;
                    break;
                default:
                    break;
            }
        }

        return $handledAny;
    }

    /**
     * Send-time детект приложенных документов (счёт/КП файлом) — та же
     * идемпотентная процедура, что синк/крон, но сразу. Только для писем с
     * вложением, привязанных к заявке. Non-fatal.
     */
    public function detectOutboundDocuments(EmailMessage $sent): void
    {
        try {
            if ($sent->related_request_id === null || $sent->attachments()->count() === 0) {
                return;
            }
            $request = Request::find($sent->related_request_id);
            if ($request === null) {
                return;
            }
            $this->mailRouter->runOutboundDocumentDetection($sent, $request);
        } catch (\Throwable $e) {
            Log::warning('OutboundReplyHooks: send-time outbound detection failed (non-fatal)', [
                'email_message_id' => $sent->id,
                'request_id' => $sent->related_request_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Foundation §6.2 — отправлены уточняющие вопросы клиенту. */
    private function handleClarificationBatchHook(EmailMessage $sent, array $marker, User $actor): void
    {
        $batchId = (int) ($marker['batch_id'] ?? 0);
        if ($batchId === 0) {
            return;
        }
        $batch = ClarificationBatch::find($batchId);
        if (! $batch) {
            return;
        }

        $batch->update([
            'status' => ClarificationBatch::STATUS_SENT,
            'sent_at' => now(),
            'sent_message_id' => $sent->id,
        ]);

        if (($marker['transition_to_status'] ?? null) !== 'awaiting_client_clarification') {
            return;
        }

        $request = $batch->request;
        if (! $request) {
            return;
        }
        try {
            $this->stateService->transitionTo(
                $request,
                RequestStatus::AwaitingClientClarification,
                $actor,
                [
                    'event' => 'clarification_sent',
                    'comment' => sprintf(
                        'Отправлены уточняющие вопросы клиенту (batch #%d, %d вопросов).',
                        $batch->id,
                        $batch->questions()->count(),
                    ),
                    'payload' => [
                        'clarification_batch_id' => $batch->id,
                        'sent_message_id' => $sent->id,
                    ],
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('OutboundReplyHooks: clarification post-send transition failed (non-fatal)', [
                'batch_id' => $batch->id,
                'request_id' => $request->id,
                'current_status' => $request->status->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Phase 4 — отправлено КП клиенту: markSent + статус Quoted. */
    private function handleQuotationSentHook(EmailMessage $sent, array $marker, User $actor): void
    {
        $qId = (int) ($marker['quotation_id'] ?? 0);
        if ($qId === 0) {
            return;
        }
        $quotation = Quotation::find($qId);
        if (! $quotation) {
            return;
        }

        try {
            $this->quotationService->markSent($quotation, $sent->id);
        } catch (\Throwable $e) {
            Log::warning('OutboundReplyHooks: quotation markSent failed (non-fatal)', [
                'quotation_id' => $quotation->id,
                'sent_message_id' => $sent->id,
                'error' => $e->getMessage(),
            ]);
        }

        $request = $quotation->request;
        if (! $request) {
            return;
        }
        try {
            $this->stateService->transitionTo(
                $request,
                RequestStatus::Quoted,
                $actor,
                [
                    'event' => 'quotation_sent',
                    'comment' => sprintf('КП %s v%d отправлено клиенту.', $quotation->internal_code, $quotation->version),
                    'payload' => [
                        'quotation_id' => $quotation->id,
                        'quotation_code' => $quotation->internal_code,
                        'quotation_version' => $quotation->version,
                        'sent_message_id' => $sent->id,
                    ],
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('OutboundReplyHooks: quotation post-send transition failed (non-fatal)', [
                'quotation_id' => $quotation->id,
                'request_id' => $request->id,
                'current_status' => $request->status->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Является ли письмо письмом поставщика (на него отвечать клиенту, не
     * поставщику). Вынесено для переиспользования в композере.
     */
    public function isSupplierMessage(EmailMessage $msg): bool
    {
        return (string) $msg->category === EmailCategory::SupplierReply->value
            || $msg->supplier_inquiry_id !== null;
    }
}
