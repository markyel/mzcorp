<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Enums\EmailCategory;
use App\Enums\RequestStatus;
use App\Services\Mail\CitedOutboundQuoteRouter;
use App\Services\Mail\EmailTextCleanerService;
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
            $cited = $this->citedQuoteRouter->detect($message);
            // Номер КП/счёта в письме — ещё не просьба о новом счёте: клиент
            // так же цитирует наш счёт, спрашивая «когда получим», «по срокам
            // успеваем?», «заберём завтра». Дочернюю заявку заводим только
            // если в СОБСТВЕННОМ тексте клиента есть просьба о счёте / дозаказ
            // (PostSaleFulfillmentDetector::wantsNewInvoiceOrOrder). Кейс
            // M-2026-14700 (и ещё ~15 фантомов за август–сентябрь 2026).
            if ($cited !== null) {
                $wants = $this->postSale->wantsNewInvoiceOrOrder(
                    (string) $message->subject,
                    $this->citedQuoteRouter->ownBodyText($message),
                    $this->cleaner->isReply($message),
                );
                if (! $wants) {
                    Log::info('MailRouter: cited quote on closed_won without invoice request → post-sale, no child', [
                        'email_message_id' => $message->id,
                        'parent_request_id' => $linkedRequest->id,
                        'document_number' => $cited['document_number'],
                    ]);
                    $cited = null;
                }
            }
            // Номера КП нет, но LLM уверенно видит НОВУЮ заявку («прошу выставить
            // счёт по наличию: M10732 - 2 шт …»), клиент просит счёт/дозаказ своим
            // текстом и есть сигналы позиций — новый заказ в старом треде:
            // разворачиваем в отдельную заявку (spin-off, как для intent=new_request).
            if ($cited === null && $message->category === EmailCategory::ClientRequest->value) {
                $wantsNew = $this->postSale->wantsNewInvoiceOrOrder(
                    (string) $message->subject,
                    $this->citedQuoteRouter->ownBodyText($message),
                    $this->cleaner->isReply($message),
                );
                if ($wantsNew && $this->parseGate->shouldParse($message)) {
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
