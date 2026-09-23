<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Models\EmailMessage;
use App\Models\Request;
use App\Services\Mail\CitedOutboundQuoteRouter;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use Illuminate\Support\Facades\Log;

/**
 * Клиент прислал обратно наше КП — работаем в той же заявке.
 *
 * Если в письме или во вложении стоит номер нашего исходящего документа, то
 * заявка, по которой он выдан, известна точно — это сильнее любых догадок по
 * теме и тексту. Продолжение разговора про выданное КП («выставите счёт»,
 * «сделайте на 4 шт») — следующая стадия той же сделки, а не новая.
 *
 * Кейс M-2026-15434 / M-2026-15464: клиент вернул «Предложение МЗ-366899» с
 * просьбой сделать счёт, и вместо продолжения завелась вторая заявка — обе на
 * одного менеджера, одна закрыта успехом, другая потерей.
 *
 * Гарды:
 *  • письмо ещё ни к чему не привязано — привязанные ведут другие ветки;
 *  • заявка жива (закрытые — к ClosedWonThreadHandler: там свои правила про
 *    новый заказ в треде выигранной сделки);
 *  • письмо пришло в личный ящик — заявка должна быть за его владельцем,
 *    иначе чужая переписка утянет заявку из пула другого менеджера.
 */
final class CitedQuoteLinkHandler implements InboundRoutingHandler
{
    public function __construct(private readonly CitedOutboundQuoteRouter $cited) {}

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;

        if ($ctx->linkedRequest !== null || $message->related_request_id !== null) {
            return null;
        }

        try {
            $found = $this->cited->detect($message);
        } catch (\Throwable $e) {
            Log::warning('CitedQuoteLinkHandler: разбор цитаты КП не удался', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($found === null) {
            return null;
        }

        /** @var Request $request */
        $request = $found['request'];
        if (! $request->status->isOpenForAssignment()) {
            return null;
        }
        if (! $this->sameOwner($message, $request)) {
            return null;
        }

        $message->forceFill(['related_request_id' => $request->id])->save();

        Log::info('CitedQuoteLinkHandler: письмо с нашим КП привязано к его заявке', [
            'email_message_id' => $message->id,
            'request_id' => $request->id,
            'document_number' => $found['document_number'],
            'invoice_intent' => $found['invoice_intent'],
        ]);

        return new RoutingDecision('cited_quote_linked', $request->id, [], runRules: true);
    }

    /**
     * Письмо в личном ящике менеджера должно оставаться в его пуле: общее
     * правило Foundation §1.5. Для общих ящиков ограничения нет.
     */
    private function sameOwner(EmailMessage $message, Request $request): bool
    {
        $ownerId = $message->mailbox?->owner_user_id;
        if ($ownerId === null) {
            return true;
        }

        if ((int) $request->assigned_user_id === (int) $ownerId) {
            return true;
        }

        return method_exists($request, 'isDelegatedTo')
            && $request->assignedUser !== null
            && $request->isDelegatedTo($message->mailbox->owner);
    }
}
