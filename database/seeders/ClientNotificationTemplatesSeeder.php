<?php

namespace Database\Seeders;

use App\Enums\ClientNotificationType;
use App\Models\ClientNotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Дефолтные русские тексты для 5 типов автоматических уведомлений клиенту.
 *
 * Все шаблоны:
 *  - is_enabled = false (admin включает явно через UI после ревью текстов);
 *  - body — Markdown с {{ placeholder }} подстановками (см. enum->placeholders);
 *  - subject — однострочный, тоже поддерживает placeholder'ы.
 *
 * Стиль текстов:
 *  - Воротный, нейтральный.
 *  - Без капса, без эмодзи, без !!! .
 *  - Подпись через {{ manager_name }} — даём «живое лицо».
 */
class ClientNotificationTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $type => $data) {
            ClientNotificationTemplate::updateOrCreate(
                ['type' => $type],
                array_merge($data, ['is_enabled' => false])
            );
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function templates(): array
    {
        return [
            ClientNotificationType::OrderReceived->value => [
                'subject_template' => 'Ваш запрос {{ request_code }} принят в работу',
                'body_template' => <<<'MD'
Здравствуйте, {{ client_name }}!

Спасибо за обращение. Ваш запрос **{{ request_code }}** принят в работу.

{{ manager_intro }}

Мы изучим запрос, и в ближайшее время вернёмся к вам с предложением. Если у вас есть уточнения — отправьте их в ответ на это письмо, оно автоматически попадёт в вашу заявку.

С уважением,
{{ manager_name }}
{{ company_name }}
MD,
                'threshold_hours' => null,
                'warning_days' => null,
            ],

            ClientNotificationType::ClarificationReminder->value => [
                'subject_template' => 'Напоминание по заявке {{ request_code }}: ожидаем уточнения',
                'body_template' => <<<'MD'
Здравствуйте, {{ client_name }}!

Мы уже {{ days_since_sent }} дн. назад отправили вам уточняющие вопросы по заявке **{{ request_code }}**:

{{ questions_summary }}

Без ваших ответов мы не можем продолжить подбор позиций. Подскажите, пожалуйста, или отправьте уточнения в ответ на это письмо.

С уважением,
{{ manager_name }}
{{ company_name }}
MD,
                'threshold_hours' => null,
                'warning_days' => null,
            ],

            ClientNotificationType::QuoteFollowupReminder->value => [
                'subject_template' => 'Напоминание по заявке {{ request_code }}: ваше решение по КП',
                'body_template' => <<<'MD'
Здравствуйте, {{ client_name }}!

Мы отправляли вам коммерческое предложение по заявке **{{ request_code }}** {{ days_since_quoted }} дн. назад на сумму {{ quote_amount }}.

Подскажите, пожалуйста, есть ли по нему вопросы или готовы ли вы перейти к выставлению счёта.

С уважением,
{{ manager_name }}
{{ company_name }}
MD,
                'threshold_hours' => null,
                'warning_days' => null,
            ],

            ClientNotificationType::InvoiceExpiringSoon->value => [
                'subject_template' => 'Срок счёта {{ invoice_number }} истекает через {{ days_until_expiry }} дн.',
                'body_template' => <<<'MD'
Здравствуйте, {{ client_name }}!

Напоминаем, что срок действия счёта **№ {{ invoice_number }}** на сумму {{ invoice_amount }} по заявке **{{ request_code }}** истекает **{{ invoice_expires_at }}** (через {{ days_until_expiry }} дн.).

Если оплата не поступит до этой даты, счёт будет аннулирован, и нам потребуется выставить новый — цены могут измениться.

Если у вас остались вопросы по оплате — напишите, постараемся помочь.

С уважением,
{{ manager_name }}
{{ company_name }}
MD,
                'threshold_hours' => null,
                'warning_days' => 3,
            ],

            ClientNotificationType::InvoiceExpired->value => [
                'subject_template' => 'Срок счёта {{ invoice_number }} по заявке {{ request_code }} истёк',
                'body_template' => <<<'MD'
Здравствуйте, {{ client_name }}!

К сожалению, срок действия счёта **№ {{ invoice_number }}** на сумму {{ invoice_amount }} по заявке **{{ request_code }}** истёк {{ invoice_expired_at }} ({{ days_since_expiry }} дн. назад).

Если оплата всё ещё актуальна — сообщите, мы выставим новый счёт с актуальными ценами.

С уважением,
{{ manager_name }}
{{ company_name }}
MD,
                'threshold_hours' => null,
                'warning_days' => null,
            ],

            ClientNotificationType::OrderClosedLost->value => [
                'subject_template' => 'Заявка {{ request_code }} закрыта',
                'body_template' => <<<'MD'
Здравствуйте, {{ client_name }}!

Заявка **{{ request_code }}** закрыта. Причина: {{ close_reason_label }}.

{{ close_comment }}

Если ситуация изменится — напишите в ответ на это письмо, мы готовы вернуться к работе.

С уважением,
{{ manager_name }}
{{ company_name }}
MD,
                'threshold_hours' => null,
                'warning_days' => null,
            ],
        ];
    }
}
