<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Enums\EmailCategory;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;

/**
 * Копия ответа поставщика из QuoteParts (режим дублирования переписки, заголовок
 * X-QuoteParts-Mirror): приходит в личный ящик автора запроса с ящика MyZip на
 * платформе. Это не письмо клиента — заявку не создаём, к заявкам не линкуем,
 * документы не ищем. Менеджер видит копию в «Почте» и отвечает на неё; ответ
 * QuoteParts сам отправит поставщику.
 */
final class QuotePartsMirrorHandler implements InboundRoutingHandler
{
    public const HEADER = 'x_quoteparts_mirror';

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;
        $headers = (array) ($message->headers ?? []);
        if (! isset($headers[self::HEADER])) {
            return null;
        }

        if ($message->category === null) {
            $message->forceFill([
                'category' => EmailCategory::Irrelevant->value,
                'category_reasoning' => 'Копия ответа поставщика из QuoteParts — ответ на неё уходит поставщику через QuoteParts.',
                'categorized_at' => now(),
                'classified_at' => now(),
            ])->save();
        }

        return new RoutingDecision('quoteparts_mirror');
    }
}
