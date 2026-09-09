<?php

namespace App\Services\Mail\Routing;

/**
 * Один шаг цепочки маршрутизации письма (MailRouter). Порядок шагов задаёт
 * RoutingPipeline. Контракт: вернуть RoutingDecision — обработка письма
 * закончена (early return прежнего route()), вернуть null — передать письмо
 * следующему шагу. Побочные эффекты шага (категория, привязки, job'ы) — те
 * же, что были в соответствующей ветке route(); при переносе логика не
 * менялась.
 */
interface InboundRoutingHandler
{
    public function handle(RoutingContext $ctx): ?RoutingDecision;
}
