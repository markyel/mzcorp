<?php

namespace App\Services\Mail\Routing;

use App\Services\Mail\Routing\Handlers\BlocklistHandler;
use App\Services\Mail\Routing\Handlers\CategorizeHandler;
use App\Services\Mail\Routing\Handlers\ClosedWonThreadHandler;
use App\Services\Mail\Routing\Handlers\CrossMailboxCopyHandler;
use App\Services\Mail\Routing\Handlers\LinkToRequestHandler;
use App\Services\Mail\Routing\Handlers\PostSaleOrderHandler;
use App\Services\Mail\Routing\Handlers\LoopForwardHandler;
use App\Services\Mail\Routing\Handlers\NotInboundHandler;
use App\Services\Mail\Routing\Handlers\OutboundHandler;
use App\Services\Mail\Routing\Handlers\ProcurementMailboxHandler;
use App\Services\Mail\Routing\Handlers\RfqInboxHandler;
use App\Services\Mail\Routing\Handlers\SupplierReplyHandler;
use App\Services\Mail\Routing\Handlers\SystemNotificationHandler;
use Illuminate\Contracts\Container\Container;

/**
 * Упорядоченная цепочка обработчиков входящего письма. ПОРЯДОК — это контракт:
 * каждый шаг ниже опирается на то, что предыдущие уже отсеяли своё
 * (напр. стоп-лист стоит до LLM-категоризации, чтобы не тратить токены;
 * копия из другого ящика — до линкера, чтобы наследовать решение оригинала).
 *
 * Стадия strangler-переноса из MailRouter::route() (2026-09-09): пока здесь
 * только ранние выходы до матчинга поставщиков; остальная часть route()
 * выполняется после цепочки как раньше и переносится следующими шагами.
 */
final class RoutingPipeline
{
    /** @var list<class-string<InboundRoutingHandler>> */
    public const HANDLERS = [
        SystemNotificationHandler::class,
        RfqInboxHandler::class,
        OutboundHandler::class,
        NotInboundHandler::class,
        LoopForwardHandler::class,
        ProcurementMailboxHandler::class,
        BlocklistHandler::class,
        CrossMailboxCopyHandler::class,
        SupplierReplyHandler::class,
        CategorizeHandler::class,
        LinkToRequestHandler::class,
        ClosedWonThreadHandler::class,
        PostSaleOrderHandler::class,
    ];

    /** @var list<InboundRoutingHandler>|null */
    private ?array $resolved = null;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Прогнать письмо по цепочке. Возвращает решение первого шага, который
     * закончил обработку, либо null — письмо идёт дальше (в остаток route()).
     */
    public function run(RoutingContext $ctx): ?RoutingDecision
    {
        foreach ($this->handlers() as $handler) {
            $decision = $handler->handle($ctx);
            if ($decision !== null) {
                return $decision;
            }
        }

        return null;
    }

    /** @return list<InboundRoutingHandler> */
    private function handlers(): array
    {
        if ($this->resolved === null) {
            $this->resolved = array_map(fn (string $class) => $this->container->make($class), self::HANDLERS);
        }

        return $this->resolved;
    }
}
