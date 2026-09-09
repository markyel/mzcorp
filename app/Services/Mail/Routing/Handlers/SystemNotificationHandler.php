<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Enums\EmailCategory;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;

/**
 * Служебные письма системы (уведомления поддержки и т.п., шлёт
 * SystemNotificationMailer с заголовком X-MyLift-System-Notification): копия
 * из «Отправленных» общего ящика и входящие копии в личных ящиках сотрудников
 * НЕ должны линковаться к заявкам (в теме бывает код заявки!), детектиться
 * как документы или плодить заявки/шум в mail-review. Помечаем категорией и
 * выходим из обработки.
 */
final class SystemNotificationHandler implements InboundRoutingHandler
{
    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;
        $sysHeaders = (array) ($message->headers ?? []);
        if (! isset($sysHeaders['x_mylift_system_notification'])) {
            return null;
        }

        if ($message->category === null) {
            $message->forceFill([
                'category' => EmailCategory::Irrelevant->value,
                'category_reasoning' => 'Служебное уведомление MyLift (поддержка) — вне обработки.',
                'categorized_at' => now(),
                'classified_at' => now(),
            ])->save();
        }

        return new RoutingDecision('system_notification');
    }
}
