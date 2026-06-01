<?php

namespace App\Services\Mail;

use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;

/**
 * Pre-classifier: письмо-просьба отгрузить / поставить на комплектацию уже
 * ОПЛАЧЕННЫЙ заказ — это post_sale, а не новая заявка.
 *
 * gpt-4o на терсовых письмах («Прошу поставить на комплектацию», тема
 * «Отгрузка», вложение-zip с платёжкой) систематически ставит client_request
 * («комплектация → запрос ТМЦ», «вложение может быть спецификацией») →
 * плодятся ошибочные заявки с назначением менеджера (тикеты M-2026-2706,
 * M-2026-2762). Промпт это не лечит — модель упорно держит client_request.
 * Здесь ловим паттерн детерминированно.
 *
 * Триггерим post_sale ТОЛЬКО при совпадении всех условий:
 *   1) в теме/теле есть «отгруз…» / «…комплектаци…» (отгрузка купленного);
 *   2) НЕТ признаков НОВОГО запроса — цены/КП или перечня с количествами
 *      (защита от «отгрузите образцы и заодно посчитайте КП на 50 роликов»);
 *   3) у клиента (from_email) есть хотя бы один заказ в статусах
 *      awaiting_invoice / invoiced / paid / closed_won — сделка реально была.
 *
 * Условия (1)+(3) уже сильные (существующий оплативший клиент + лексика
 * отгрузки); (2) — предохранитель от ложных срабатываний на новых КП/заказах.
 * Даже при ложном срабатывании потери письма нет: MailRouter прицепит его к
 * последнему оплаченному заказу клиента и уведомит его менеджера — тот при
 * необходимости заведёт заявку руками (это лучше, чем авто-заявка на каждое
 * постпродажное письмо).
 */
class PostSaleFulfillmentDetector
{
    /** Лексика отгрузки/комплектации уже купленного. */
    private const FULFILLMENT_STEMS = ['отгруз', 'комплектац'];

    /**
     * Признаки НОВОГО запроса (цена/КП/перечень с количествами). Если хоть
     * один найден — это не чистая отгрузка, отдаём решение LLM.
     */
    private const NEW_ORDER_MARKERS = [
        'коммерческое предложение',
        'пришлите кп',
        'нужно кп',
        'нужна кп',
        'дайте кп',
        'посчита',
        'просчита',
        'рассчита',
        'прайс',
        'стоимост',
        'почём',
        'почем',
        'цена',
        'цену',
        'цены',
        ' шт',
        'штук',
    ];

    private const PAID_STATUSES = [
        RequestStatus::AwaitingInvoice->value,
        RequestStatus::Invoiced->value,
        RequestStatus::Paid->value,
        RequestStatus::ClosedWon->value,
    ];

    /**
     * @return string|null  Причина (для лога/reasoning) либо null — паттерн не сработал.
     */
    public function detect(EmailMessage $message): ?string
    {
        $from = (string) $message->from_email;
        if ($from === '') {
            return null;
        }

        $haystack = mb_strtolower(
            ((string) $message->subject) . "\n" . ((string) $message->body_plain)
        );

        $hasFulfillment = false;
        foreach (self::FULFILLMENT_STEMS as $stem) {
            if (str_contains($haystack, $stem)) {
                $hasFulfillment = true;
                break;
            }
        }
        if (! $hasFulfillment) {
            return null;
        }

        foreach (self::NEW_ORDER_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return null;
            }
        }

        $paidOrder = Request::query()
            ->where('client_email', $from)
            ->whereIn('status', self::PAID_STATUSES)
            ->orderByDesc('created_at')
            ->first(['id', 'internal_code', 'status']);

        if ($paidOrder === null) {
            return null;
        }

        return sprintf(
            'отгрузка/комплектация по оплаченному заказу %s (%s)',
            $paidOrder->internal_code,
            $paidOrder->status?->value ?? '—',
        );
    }
}
