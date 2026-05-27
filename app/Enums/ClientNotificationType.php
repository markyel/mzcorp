<?php

namespace App\Enums;

/**
 * Типы автоматических уведомлений клиенту.
 *
 * Каждый тип имеет:
 *  - row в таблице `client_notification_templates` с шаблоном (редактируется в Admin UI);
 *  - toggle `is_enabled` (admin может выключить любой тип);
 *  - триггер (sync hook или cron job);
 *  - идемпотентность через `client_notifications_sent` уникальный (request_id, type, [scope_key]).
 *
 * Все уведомления отправляются как **reply в тред заявки**:
 *  - From: ящик в который пришёл оригинал (mail@/info@/personal manager).
 *  - In-Reply-To/References: на конкретное письмо клиента из тред'а заявки.
 *  - Клиент видит "продолжение переписки", его ответ через "Reply" попадает обратно.
 */
enum ClientNotificationType: string
{
    /**
     * «Ваша заявка принята в работу». Триггерится синхронно после
     * AssignmentService::autoAssign для НОВЫХ заявок:
     *  - Request.inheritance_parent_id IS NULL (не наследник)
     *  - origin EmailMessage.in_reply_to IS NULL (не reply на чужой тред)
     *  - Request не из inheritance fallback
     */
    case OrderReceived = 'order_received';

    /**
     * «Напоминаем — мы задавали уточняющий вопрос». Cron.
     * Условия: ClarificationBatch.status=sent, не answered,
     * sent_at < now - threshold. Использует existing AttentionService thresholds.
     */
    case ClarificationReminder = 'clarification_reminder';

    /**
     * «Прошло N дней, ожидаем вашего решения по КП». Cron.
     * Условия: Request.status=Quoted, transitioned_at < now - threshold,
     * нет InvoiceSent / InvoiceExpired в этой заявке.
     */
    case QuoteFollowupReminder = 'quote_followup_reminder';

    /**
     * «Срок действия счёта истекает через N дней». Cron.
     * Условия: Invoice активен (не paid/cancelled), expires_at < now + warning_window.
     */
    case InvoiceExpiringSoon = 'invoice_expiring_soon';

    /**
     * «Срок действия счёта истёк». Cron.
     * Условия: Invoice.expires_at < now, не paid/cancelled, не отправляли expired-нотификацию.
     */
    case InvoiceExpired = 'invoice_expired';

    /**
     * «Заявка закрыта». Sync hook после успешного RequestStateService::transitionTo
     * в ClosedLost. Guard: НЕ слать если закрытие пришло из outbound-сигнала
     * менеджера (detector_type=outbound_declined) — он уже написал клиенту своё
     * сообщение и повторное уведомление избыточно. Слать для:
     *  - manual UI close (CloseLostDialog) — менеджер не писал клиенту;
     *  - inbound_decline (клиент сам отказался) — подтверждение что мы услышали;
     *  - системного закрытия (auto-recover) — клиент в курсе быть должен.
     */
    case OrderClosedLost = 'order_closed_lost';

    public function label(): string
    {
        return match ($this) {
            self::OrderReceived => 'Заявка принята в работу',
            self::ClarificationReminder => 'Напоминание об уточнении',
            self::QuoteFollowupReminder => 'Напоминание после КП',
            self::InvoiceExpiringSoon => 'Скоро истечёт срок счёта',
            self::InvoiceExpired => 'Срок счёта истёк',
            self::OrderClosedLost => 'Заявка закрыта',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::OrderReceived => 'Отправляется автоматически сразу после создания и назначения заявки. НЕ отправляется, если заявка пришла как продолжение существующей переписки. Если письмо пришло на общий ящик (info@), указывается ответственный менеджер; если на личный — этот блок скрывается автоматически.',
            self::ClarificationReminder => 'Если клиент не ответил на уточняющий вопрос в течение заданного срока — отправляется напоминание.',
            self::QuoteFollowupReminder => 'Если после отправки коммерческого предложения клиент не отреагировал в течение заданного срока.',
            self::InvoiceExpiringSoon => 'За несколько дней до истечения срока действия выставленного счёта.',
            self::InvoiceExpired => 'Сразу после того как срок действия счёта истёк.',
            self::OrderClosedLost => 'Отправляется при закрытии заявки вручную через UI или после явного отказа клиента. НЕ отправляется, если заявку закрыл сам менеджер своим письмом-отказом («не наш профиль»), потому что клиент уже прочитал ответ.',
        };
    }

    /**
     * Список плейсхолдеров, доступных для подстановки в template
     * данного типа. UI показывает их рядом с редактируемым текстом.
     *
     * @return array<string, string> placeholder => description
     */
    public function placeholders(): array
    {
        $common = [
            'request_code' => 'Внутренний код заявки (M-2026-NNNN)',
            'manager_name' => 'Имя ответственного менеджера',
            'manager_email' => 'Email ответственного менеджера',
            'manager_phone' => 'Телефон менеджера (если заполнен в профиле)',
            'client_name' => 'Имя клиента из From-поля письма',
            'company_name' => 'Название нашей компании (MyZip)',
        ];

        $specific = match ($this) {
            self::OrderReceived => [
                'items_count' => 'Количество позиций в заявке',
                'items_summary' => 'Краткий список позиций',
                'manager_intro' => 'Условный блок: «Ответственный менеджер: Имя (email).» Подставляется только если письмо пришло на общий ящик (info@/mail@). Для писем на личный ящик менеджера — пустая строка (клиент уже знает, к кому пишет).',
            ],
            self::ClarificationReminder => [
                'days_since_sent' => 'Сколько дней назад отправили вопрос',
                'questions_summary' => 'Краткий список заданных вопросов',
            ],
            self::QuoteFollowupReminder => [
                'days_since_quoted' => 'Сколько дней назад отправили КП',
                'quote_amount' => 'Сумма коммерческого предложения',
            ],
            self::InvoiceExpiringSoon => [
                'invoice_number' => 'Номер счёта',
                'invoice_amount' => 'Сумма счёта',
                'invoice_expires_at' => 'Дата истечения срока действия счёта (DD.MM.YYYY)',
                'days_until_expiry' => 'Сколько дней до истечения',
            ],
            self::InvoiceExpired => [
                'invoice_number' => 'Номер счёта',
                'invoice_amount' => 'Сумма счёта',
                'invoice_expired_at' => 'Дата истечения срока действия счёта (DD.MM.YYYY)',
                'days_since_expiry' => 'Сколько дней прошло с истечения',
            ],
            self::OrderClosedLost => [
                'close_reason_label' => 'Человеческое описание причины закрытия (из ClosedLostReason enum)',
                'close_comment' => 'Комментарий менеджера к закрытию (если заполнен)',
            ],
        };

        return array_merge($common, $specific);
    }

    /** Триггер — синхронный (sync hook) или асинхронный (cron). */
    public function isSyncTrigger(): bool
    {
        return $this === self::OrderReceived;
    }

    /** @return self[] */
    public static function syncTriggerCases(): array
    {
        return array_filter(self::cases(), fn ($c) => $c->isSyncTrigger());
    }

    /** @return self[] */
    public static function cronTriggerCases(): array
    {
        return array_filter(self::cases(), fn ($c) => ! $c->isSyncTrigger());
    }
}
