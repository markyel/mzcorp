<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Models\EmailMessage;
use App\Models\RoutedMail;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use Illuminate\Support\Facades\Log;

/**
 * Антициклическая защита: письма, уже когда-то пересланные нашим
 * MailForwarder'ом, имеют заголовок X-MyLift-Forwarded или префикс
 * [MyLift forward] в subject. Не маршрутизируем их повторно.
 */
final class LoopForwardHandler implements InboundRoutingHandler
{
    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;
        if (! $this->isLoopMessage($message)) {
            return null;
        }

        RoutedMail::create([
            'email_message_id' => $message->id,
            'rule_id' => null,
            'ai_classified_as' => $message->category,
            'action_taken' => 'loop_skipped',
            'success' => true,
            'processed_at' => now(),
        ]);
        Log::info('MailRouter: loop guard triggered, skipping rules', [
            'email_message_id' => $message->id,
            'subject' => mb_substr((string) $message->subject, 0, 80),
            'from' => $message->from_email,
        ]);

        return new RoutingDecision('loop_forward');
    }

    /**
     * Письмо — это вернувшийся к нам наш собственный forward?
     * Проверяем по X-MyLift-Forwarded заголовку и subject-префиксу.
     */
    private function isLoopMessage(EmailMessage $message): bool
    {
        $headers = (array) ($message->headers ?? []);
        // headers — jsonb; ключи могут быть в любом регистре.
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'X-MyLift-Forwarded') === 0) {
                return true;
            }
            if (strcasecmp((string) $name, 'x_mylift_forwarded') === 0) {
                return true;
            }
        }

        return str_starts_with((string) $message->subject, '[MyLift forward]');
    }
}
