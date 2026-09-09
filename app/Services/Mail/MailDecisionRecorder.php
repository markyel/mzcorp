<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\MailDecision;
use Illuminate\Support\Facades\Log;

/**
 * Журнал решений маршрутизатора: одна строка на каждый выход из
 * MailRouter::route(). Никогда не бросает — запись решения не должна ломать
 * обработку письма.
 *
 * Стадии — закрытый список (STAGES): код → исход + человекочитаемая подпись.
 * Исходы: skipped (письмо не обрабатывается как клиентское), supplier
 * (переписка с поставщиком), copy (копия из другого ящика), post_sale,
 * linked (привязано к существующей заявке), created (создана заявка),
 * outbound (наше исходящее), none (заявка не создана и не привязана).
 */
class MailDecisionRecorder
{
    /** @var array<string, array{0:string,1:string}> код → [исход, подпись] */
    public const STAGES = [
        'system_notification' => ['skipped', 'Служебное уведомление MyLift'],
        'rfq_inbox' => ['supplier', 'Ящик rfq@: копия переписки с поставщиком'],
        'outbound' => ['outbound', 'Наше исходящее письмо'],
        'not_inbound' => ['skipped', 'Не входящее письмо'],
        'loop_forward' => ['skipped', 'Петля пересылки MyLift'],
        'procurement_mailbox' => ['supplier', 'Ящик снабжения: переписка с поставщиком'],
        'blocklist_supplier' => ['supplier', 'Отправитель в стоп-листе как поставщик'],
        'blocklist_spam' => ['skipped', 'Отправитель в стоп-листе (спам)'],
        'cross_mailbox_copy' => ['copy', 'Копия письма из другого ящика — наследует решение оригинала'],
        'supplier_thread' => ['supplier', 'Ответ поставщика по треду/токену RFQ'],
        'supplier_sender_code' => ['supplier', 'Ответ поставщика: отправитель + код заявки'],
        'supplier_rfq_subject' => ['supplier', 'Ответ поставщика по теме RFQ (тред сломан)'],
        'supplier_rfq_unregistered' => ['supplier', 'Тема RFQ, запрос поставщику ещё не зарегистрирован'],
        'closed_won_spin_off' => ['created', 'Новый заказ в треде выигранной сделки — отдельная заявка'],
        'closed_won_invoice_child' => ['created', 'Цитата КП по выигранной сделке — дочерняя заявка на счёт'],
        'closed_won_post_sale' => ['post_sale', 'Постпродажа по выигранной сделке'],
        'post_sale_order' => ['post_sale', 'Постпродажа по оформленному заказу'],
        'post_sale_unlinked' => ['none', 'Постпродажа без надёжной привязки к заказу — оставлено во входящих'],
        'thread_spin_off' => ['created', 'Новая заявка в старом треде (интент клиента)'],
        'thread_reply' => ['linked', 'Ответ клиента в тред существующей заявки'],
        'request_created' => ['created', 'Создана новая заявка'],
        'request_existing' => ['linked', 'Заявка по письму уже была'],
        'request_rejected' => ['none', 'Процессор заявку не создал (пустое письмо / поставщик / стоп-лист)'],
        'not_a_request' => ['none', 'Категория письма не предполагает заявку'],
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(EmailMessage $message, string $stage, ?int $requestId = null, array $payload = []): void
    {
        try {
            [$outcome, $label] = self::STAGES[$stage] ?? ['none', $stage];
            $reason = (string) ($payload['reason'] ?? $label);
            unset($payload['reason']);

            MailDecision::create([
                'email_message_id' => $message->id,
                'mailbox_id' => $message->mailbox_id,
                'stage' => $stage,
                'outcome' => $outcome,
                'request_id' => $requestId ?? $message->related_request_id,
                'category' => $message->category,
                'reason' => mb_substr($reason, 0, 500),
                'payload' => $payload !== [] ? $payload : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('MailDecisionRecorder: failed to record decision (non-fatal)', [
                'email_message_id' => $message->id,
                'stage' => $stage,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Подпись стадии для UI. */
    public static function label(string $stage): string
    {
        return self::STAGES[$stage][1] ?? $stage;
    }
}
