<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Services\Mail\MailCategoryClassifier;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1.8c: категоризация (LazyLift drop-in). Заполняет
 * email_messages.category — для дальнейших шагов (linker уровня 4 использует
 * это как сигнал, парсер позиций — как gate). Решения не принимает — только
 * побочный эффект. ВАЖНО: стоит ДО LinkToRequestHandler, потому что 4-й
 * уровень линкера (от from_email) опирается на category=thread_reply /
 * client_request.
 */
final class CategorizeHandler implements InboundRoutingHandler
{
    public function __construct(private readonly MailCategoryClassifier $categorizer)
    {
    }

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;
        try {
            $this->categorizer->categorize($message);
            $message->refresh();
        } catch (\Throwable $e) {
            Log::warning('MailRouter: category classifier failed (non-fatal)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
