<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use App\Services\Mail\SupplierCcInboxService;

/**
 * Ящик rfq@mzcorp.ru — копии ВНЕШНЕЙ переписки с поставщиками. Не создаём
 * клиентских заявок: по номеру M-YYYY-NNNN в теме/теле привязываем письмо к
 * заявке и уведомляем менеджера. См. SupplierCcInboxService. Ранний выход —
 * минуя весь клиентский пайплайн.
 */
final class RfqInboxHandler implements InboundRoutingHandler
{
    public function __construct(private readonly SupplierCcInboxService $supplierCcInbox)
    {
    }

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        if (! $this->supplierCcInbox->isRfqInboxMessage($ctx->message)) {
            return null;
        }
        $this->supplierCcInbox->ingest($ctx->message);

        return new RoutingDecision('rfq_inbox');
    }
}
