<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Enums\MailDirection;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;

/** Всё, что не входящее (направление не задано и т.п.), дальше не идёт. */
final class NotInboundHandler implements InboundRoutingHandler
{
    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        return $ctx->message->direction !== MailDirection::Inbound
            ? new RoutingDecision('not_inbound')
            : null;
    }
}
