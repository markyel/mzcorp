<?php

namespace Tests\Unit\Services\DocumentDetector;

use App\Enums\DetectorType;
use App\Enums\RequestStatus;
use App\Services\DocumentDetector\InboundIntentClassifier;
use PHPUnit\Framework\TestCase;

/**
 * MZ2026-01114 (13.11.2026): «Скиньте счёт на 1 шт», через 4 минуты — «прошу прощения отбой, не надо пока счёт».
 * Модель прочитала второе письмо как «отложил», заявка осталась в «ждёт счёт».
 */
class InboundInvoiceWithdrawnTest extends TestCase
{
    public function test_withdrawn_invoice_requests_are_recognised(): void
    {
        foreach ([
            'Дмитрий, прошу прощения отбой, не надо пока счет',
            'Счёт не нужен, вопрос снят',
            'Не выставляйте пока счёт, руководство ещё думает',
            'Отмените, пожалуйста, счёт — заказ переносится',
            'Счет пока не нужен.',
            'Заказчик думает. Счёта пока не надо.',
            'Здравствуйте, Выставлять счет пока не надо, Благодарю.',
            'Спасибо, коммерческое получил. Счет пока выставлять не надо',
            'Добрый день, спасибо за КП, нет, не нужно выставлять счет',
            'Андрей, добрый день! Счет пока не требуется.',
        ] as $text) {
            $this->assertTrue(InboundIntentClassifier::withdrawsInvoiceRequest($text), $text);
        }
    }

    public function test_an_invoice_request_is_not_a_withdrawal(): void
    {
        foreach ([
            'Скиньте счет на 1 шт бонусная карта №20006176',
            'Прошу выставить счёт с доставкой до Казани',
            'Счёт получили, оплатим завтра',
            'Не забудьте приложить бонусную карту к счёту',
            // Ложные срабатывания первой версии на письмах за 60 дней.
            'Мне нужен счет а не кп.',
            'Мне нужен новый счет без НДС.',
            'Можете счет выставить. Доставку не нужно указывать.',
            'Добрый день. Выставляйте счет. Доставки не надо.',
            'Обновите цену как в счете. * Поручень не нужен, его исключите.',
            'Товар отгрузили, мы не отправляем "за счет" получателя',
            'По счету стоимость актуальна? Обновлять счет не нужно?',
            'Это письмо — предупреждение о возможной ошибке в счет и ничего нажимать не нужно',
            '',
        ] as $text) {
            $this->assertFalse(InboundIntentClassifier::withdrawsInvoiceRequest($text), $text);
        }
    }

    public function test_the_new_type_returns_the_request_to_quoted(): void
    {
        $this->assertSame(RequestStatus::Quoted, DetectorType::InboundInvoiceWithdrawn->targetStatus());
        $this->assertContains(RequestStatus::Quoted, RequestStatus::AwaitingInvoice->allowedTransitions());
    }
}
