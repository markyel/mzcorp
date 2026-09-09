<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Enums\EmailCategory;
use App\Services\Mail\IncomingMailProcessor;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;

/**
 * Последний шаг: для писем-заявок создаём Request. Решение — строго по
 * EmailCategory (gpt-4o, Phase 1.8c): client_request и thread_reply
 * (для «висящих» thread_reply без найденного треда — forward старой
 * переписки — иначе заявка не создавалась). post_sale без надёжного заказа
 * (ctx->postSaleUnlinked) заявку НЕ создаёт — письмо остаётся в общем ящике
 * (тикет M-2026-2706). IncomingMailProcessor идемпотентен по
 * related_request_id и сам повторяет гарды стоп-листа/снабжения/поставщика.
 *
 * Всегда принимает решение (это конец цепочки) и просит прогнать правила
 * маршрутизации (forward/label) — как «хвост» прежнего route().
 */
final class CreateRequestHandler implements InboundRoutingHandler
{
    private const CREATE_CATEGORIES = [
        EmailCategory::ClientRequest->value,
        EmailCategory::ThreadReply->value,
    ];

    public function __construct(private readonly IncomingMailProcessor $incoming)
    {
    }

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;

        if ($ctx->postSaleUnlinked) {
            return new RoutingDecision('post_sale_unlinked', null, [], runRules: true);
        }

        if (! in_array($message->category, self::CREATE_CATEGORIES, true)) {
            return new RoutingDecision('not_a_request', null, [], runRules: true);
        }

        $processed = $this->incoming->processIfRequest($message);
        if ($processed === null) {
            return new RoutingDecision('request_rejected', null, [], runRules: true);
        }

        // wasRecentlyCreated теряется после fresh()/touch внутри процессора —
        // «создана в этом проходе» = заявка по этому письму моложе минуты.
        $createdNow = $processed->wasRecentlyCreated
            || ((int) $processed->email_message_id === (int) $message->id && $processed->created_at?->gte(now()->subMinute()));

        return new RoutingDecision($createdNow ? 'request_created' : 'request_existing', $processed->id, [], runRules: true);
    }
}
