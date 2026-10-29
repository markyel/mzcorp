<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Enums\EmailCategory;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use App\Services\Marketing\PressMailingService;
use Illuminate\Support\Facades\Log;

/**
 * Ответ редакции на наш пресс-релиз (медиаплан → «Пресс-релизы»).
 *
 * Матч только по треду: In-Reply-To / References = Message-ID письма
 * рассылки. Такое письмо — не заявка: привязываем к рассылке (релиз видит
 * «ответили») и останавливаем маршрутизацию ДО категоризации и линкера.
 * Письмо остаётся во входящих ящика, где его и прочитают.
 */
final class PressReplyHandler implements InboundRoutingHandler
{
    public function __construct(private readonly PressMailingService $press)
    {
    }

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;

        try {
            $send = $this->press->matchReply($message);
        } catch (\Throwable $e) {
            Log::warning('MailRouter: press reply match failed (non-fatal)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($send === null) {
            return null;
        }

        $this->press->attachReply($send, $message);
        $message->forceFill([
            'category' => EmailCategory::Irrelevant->value,
            'categorized_at' => now(),
        ])->save();

        Log::info('MailRouter: reply to our press release — attached, no request', [
            'email_message_id' => $message->id,
            'press_release_send_id' => $send->id,
            'from_email' => $message->from_email,
        ]);

        return new RoutingDecision('press_reply', null, [
            'press_release_id' => $send->press_release_id,
            'press_release_send_id' => $send->id,
        ]);
    }
}
