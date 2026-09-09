<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use App\Services\Supplier\SupplierInquiryService;
use Illuminate\Support\Facades\Log;

/**
 * Ящик снабжения: вся входящая — переписка с ПОСТАВЩИКОМ, клиентские заявки
 * из неё НЕ создаём. ingestSupplierMessage сам сматчит ответ к нужному RFQ
 * (matchInbound / matchInboundByAnyCode), иначе положит как читаемую
 * переписку поставщика. Ранний выход — до blocklist/categorize/reply-linker/
 * создания заявки. См. Mailbox::isProcurementMailbox.
 */
final class ProcurementMailboxHandler implements InboundRoutingHandler
{
    public function __construct(private readonly SupplierInquiryService $supplierInquiries)
    {
    }

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;
        $inboundMailbox = $message->mailbox;
        if ($inboundMailbox === null || ! $inboundMailbox->isProcurementMailbox()) {
            return null;
        }

        try {
            $inquiry = $this->supplierInquiries->ingestSupplierMessage($message);
            Log::info('MailRouter: procurement mailbox — read as supplier correspondence, no request', [
                'email_message_id' => $message->id,
                'mailbox_id' => $inboundMailbox->id,
                'supplier_inquiry_id' => $inquiry?->id,
                'internal_sender' => $inquiry === null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('MailRouter: procurement mailbox ingest failed (non-fatal)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        return new RoutingDecision('procurement_mailbox');
    }
}
