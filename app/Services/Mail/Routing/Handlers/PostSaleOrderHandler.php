<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Enums\EmailCategory;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\PostSaleRoutingSupport;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use Illuminate\Support\Facades\Log;

/**
 * Постпродажная переписка по уже оформленному заказу (отгрузка / комплектация /
 * документы). Новую заявку НЕ создаём и менеджера НЕ назначаем.
 *
 * Привязка к заказу — ТОЛЬКО при надёжном совпадении линкера по
 * заголовкам/коду и только если заказ «оплачен/закрыт» (тогда алерт менеджеру
 * + доставка письма в ящик). Угадывать заказ по from_email НЕЛЬЗЯ (тикет
 * M-2026-2762). Если надёжной привязки нет — письмо остаётся в общем ящике
 * нетронутым, без заявки (ctx->postSaleUnlinked, create-гейт ниже его не
 * подхватывает — тикет M-2026-2706).
 */
final class PostSaleOrderHandler implements InboundRoutingHandler
{
    public function __construct(private readonly PostSaleRoutingSupport $support)
    {
    }

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;
        if ($message->category !== EmailCategory::PostSale->value) {
            return null;
        }

        $postSaleRequest = $this->support->resolvePostSaleRequest($ctx->linkedRequest);
        if ($postSaleRequest !== null) {
            if ($message->related_request_id !== $postSaleRequest->id) {
                $message->forceFill(['related_request_id' => $postSaleRequest->id])->save();
            }
            $this->support->handlePostSaleMessage($message, $postSaleRequest);

            return new RoutingDecision('post_sale_order', $postSaleRequest->id);
        }

        Log::info('MailRouter: post_sale — no reliable order link, left untouched in inbox', [
            'email_message_id' => $message->id,
            'from_email' => $message->from_email,
        ]);
        $ctx->postSaleUnlinked = true;

        return null;
    }
}
