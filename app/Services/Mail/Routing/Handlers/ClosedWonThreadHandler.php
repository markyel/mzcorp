<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Enums\EmailCategory;
use App\Enums\RequestStatus;
use App\Services\Mail\CitedOutboundQuoteRouter;
use App\Services\Mail\EmailTextCleanerService;
use App\Services\Mail\InvoiceMentionMatcher;
use App\Services\Mail\PostSaleFulfillmentDetector;
use App\Services\Mail\ReplyParseGate;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\PostSaleRoutingSupport;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use App\Services\Request\CitedInvoiceChildService;
use App\Services\Request\RequestExtensionService;
use Illuminate\Support\Facades\Log;

/**
 * Письмо привязано к УСПЕШНО закрытой сделке (closed_won). Три исхода:
 *  - клиент цитирует наш КП/счёт И просит новый счёт → ДОЧЕРНЯЯ заявка на
 *    счёт (тот же менеджер, позиции из КП; кейс M-2026-11741), воскрешать
 *    closed_won НЕЛЬЗЯ;
 *  - номера КП нет, но LLM видит новую заявку, клиент просит счёт/дозаказ и
 *    есть сигналы позиций → отдельная заявка (spin-off; кейс M-2026-8429);
 *  - иначе — постпродажа: статус не трогаем, позиции не парсим, только
 *    attention + доставка менеджеру (кейс M-2026-11863→11309).
 */
final class ClosedWonThreadHandler implements InboundRoutingHandler
{
    public function __construct(
        private readonly CitedOutboundQuoteRouter $citedQuoteRouter,
        private readonly PostSaleFulfillmentDetector $postSale,
        private readonly EmailTextCleanerService $cleaner,
        private readonly ReplyParseGate $parseGate,
        private readonly CitedInvoiceChildService $invoiceChildren,
        private readonly RequestExtensionService $extension,
        private readonly PostSaleRoutingSupport $support,
    ) {
    }

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;
        $linkedRequest = $ctx->linkedRequest;
        if ($linkedRequest === null || $linkedRequest->status !== RequestStatus::ClosedWon) {
            return null;
        }

        try {
            $citedRaw = $this->citedQuoteRouter->detect($message);
            $cited = $citedRaw;
            $own = $this->citedQuoteRouter->ownBodyText($message);
            $isReply = $this->cleaner->isReply($message);
            // Два вопроса по собственному тексту клиента:
            //  - wantsNew — просит новый счёт/КП/дозаказ вообще (не вопрос о сроках
            //    и отгрузке — «когда получим», «заберём завтра» → постпродажа;
            //    кейс M-2026-14700 и ещё ~15 фантомов за август–сентябрь 2026);
            //  - asksInvoice — при этом просит именно СЧЁТ / собирается платить по
            //    цитируемому КП (те же позиции) → дочерняя заявка на счёт с
            //    позициями из КП. Иначе (просит КП на ДРУГИЕ позиции: «дайте КП на
            //    отводки … 1 шт») → отдельная заявка, позиции из письма. Кейс
            //    M-2026-15205: номер старого КП в теме + новые позиции → ошибочно
            //    родилась дочерняя «на счёт» по старому КП.
            // asksInvoice считается только внутри wantsNew: наш же subject «Счет на
            // оплату № 6272» иначе выглядит как просьба о счёте (93741).
            $wantsNew = $this->postSale->wantsNewInvoiceOrOrder((string) $message->subject, $own, $isReply);
            $asksInvoice = $wantsNew
                && (new InvoiceMentionMatcher)->requestsInvoiceOrIntendsToPay((string) $message->subject . "\n" . $own);
            if ($cited !== null && ! $asksInvoice) {
                Log::info('MailRouter: cited quote on closed_won without invoice request → no invoice child', [
                    'email_message_id' => $message->id,
                    'parent_request_id' => $linkedRequest->id,
                    'document_number' => $cited['document_number'],
                    'wants_new_order' => $wantsNew,
                ]);
                $cited = null;
            }
            // Новый заказ / новое КП в старом треде (LLM видит заявку либо есть
            // цитата КП с новыми позициями), сигналы позиций есть — разворачиваем
            // в отдельную заявку (spin-off, как для intent=new_request); позиции
            // распарсятся из письма. Кейсы M-2026-8429 (101376), M-2026-15205 (101843).
            if ($cited === null && $wantsNew
                && ($message->category === EmailCategory::ClientRequest->value || $citedRaw !== null)) {
                if ($this->parseGate->shouldParse($message)) {
                    $new = $this->extension->spinOffNewRequest($message, $linkedRequest);
                    if ($new !== null) {
                        Log::info('MailRouter: new order in a closed_won thread → spun off into a new request', [
                            'email_message_id' => $message->id,
                            'parent_request_id' => $linkedRequest->id,
                            'new_request_id' => $new->id,
                        ]);

                        return new RoutingDecision('closed_won_spin_off', $new->id, ['parent_request_id' => $linkedRequest->id]);
                    }
                }
            }
            if ($cited !== null) {
                $child = $this->invoiceChildren->createFromCitedQuote($message, $linkedRequest, (string) $cited['document_number']);
                if ($child !== null) {
                    Log::info('MailRouter: cited invoice on closed_won → invoice child created', [
                        'email_message_id' => $message->id,
                        'parent_request_id' => $linkedRequest->id,
                        'child_request_id' => $child->id,
                        'document_number' => $cited['document_number'],
                    ]);

                    return new RoutingDecision('closed_won_invoice_child', $child->id, [
                        'parent_request_id' => $linkedRequest->id,
                        'document_number' => $cited['document_number'],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MailRouter: cited-invoice child on closed_won failed (non-fatal, fall back to post_sale)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Иначе — обычная постпродажная переписка (отгрузка/документы):
        // статус НЕ трогаем, позиции НЕ парсим, новую заявку НЕ плодим.
        if ($message->category !== EmailCategory::PostSale->value) {
            $message->forceFill(['category' => EmailCategory::PostSale->value])->save();
        }
        $this->support->handlePostSaleMessage($message, $linkedRequest);

        return new RoutingDecision('closed_won_post_sale', $linkedRequest->id);
    }
}
