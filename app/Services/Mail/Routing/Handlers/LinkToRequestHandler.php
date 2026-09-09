<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Enums\EmailCategory;
use App\Services\Mail\CitedOutboundQuoteRouter;
use App\Services\Mail\InboundReplyLinker;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1.9 (inbound-часть): прицепить письмо к существующей Request через
 * 5 уровней линкера — In-Reply-To / References / subject-code / from_email
 * open-requests / AI multi-choice clarifier. Затем — цитата нашего КП/счёта
 * (номером в теме/теле или приложенным файлом): письмо уходит на заявку
 * этого КП и, если клиент просит счёт, — в «ждёт счёт». Только если
 * thread-линковки нет (прямой ответ в тред надёжнее).
 *
 * Решения не принимает — заполняет ctx->linkedRequest для следующих шагов.
 */
final class LinkToRequestHandler implements InboundRoutingHandler
{
    public function __construct(
        private readonly InboundReplyLinker $replyLinker,
        private readonly CitedOutboundQuoteRouter $citedQuoteRouter,
    ) {
    }

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;

        try {
            $linkedRequest = $this->replyLinker->tryLink($message);
        } catch (\Throwable $e) {
            $linkedRequest = null;
            Log::warning('MailRouter: reply linker failed (non-fatal)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($linkedRequest === null
            && in_array($message->category, [EmailCategory::ClientRequest->value, EmailCategory::ThreadReply->value], true)) {
            try {
                $cited = $this->citedQuoteRouter->detect($message);
                if ($cited !== null) {
                    $linkedRequest = $this->citedQuoteRouter->applyInvoiceRequest($message, $cited);
                }
            } catch (\Throwable $e) {
                Log::warning('MailRouter: cited-quote routing failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $ctx->linkedRequest = $linkedRequest;

        return null;
    }
}
