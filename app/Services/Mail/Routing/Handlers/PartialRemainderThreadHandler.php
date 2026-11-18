<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Enums\RequestStatus;
use App\Services\Mail\CitedOutboundQuoteRouter;
use App\Services\Mail\EmailTextCleanerService;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use App\Services\Quotes\PartialQuoteRemainderService;
use Illuminate\Support\Facades\Log;

/**
 * Письмо в треде заявки, закрытой успехом после оплаты частичного КП, — про её ОСТАТОК (позиции без КП переехали в
 * дочернюю заявку-остаток, PartialQuoteRemainderService). Клиент называет позицию остатка, пишет «по остальным
 * позициям» или цитирует КП, выданное по остатку, — письмо уходит в заявку-остаток, и дальше его разбирает
 * LinkedThreadHandler как обычный ответ в открытую заявку. Иначе — ничего: постпродажа закрытой заявки
 * (ClosedWonThreadHandler).
 */
final class PartialRemainderThreadHandler implements InboundRoutingHandler
{
    public function __construct(
        private readonly PartialQuoteRemainderService $remainders,
        private readonly CitedOutboundQuoteRouter $citedQuoteRouter,
        private readonly EmailTextCleanerService $cleaner,
    ) {
    }

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;
        $closed = $ctx->linkedRequest;
        if ($closed === null || $closed->status !== RequestStatus::ClosedWon) {
            return null;
        }

        try {
            $child = $this->remainders->remainderOf($closed);
            if ($child === null) {
                return null;
            }

            $cited = $this->citedQuoteRouter->detect($message);
            $citesRemainderQuote = $cited !== null && $cited['request']->id === $child->id;
            // В ответе тема — наследство треда, не слова клиента.
            $own = ($this->cleaner->isReply($message) ? '' : (string) $message->subject."\n")
                .$this->citedQuoteRouter->ownBodyText($message);
            if (! $citesRemainderQuote && $this->remainders->remainderForMessage($closed, $own) === null) {
                return null;
            }

            $message->forceFill(['related_request_id' => $child->id])->save();
            $ctx->linkedRequest = $child->fresh();

            Log::info('MailRouter: reply about the remainder of a won request → remainder request', [
                'email_message_id' => $message->id,
                'closed_request_id' => $closed->id,
                'remainder_request_id' => $child->id,
                'by' => $citesRemainderQuote ? 'cited_quote' : 'items',
            ]);
        } catch (\Throwable $e) {
            Log::warning('MailRouter: partial remainder routing failed (non-fatal, stays with the closed request)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
