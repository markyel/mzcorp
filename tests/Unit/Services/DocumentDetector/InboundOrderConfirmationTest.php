<?php

namespace Tests\Unit\Services\DocumentDetector;

use App\Services\DocumentDetector\InboundIntentClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Подтверждение заказа не должно читаться как «клиент добавил позиции»
 * (M-2026-17148: «Андрей, прошу поставить на комплектацию» после счёта
 * откатило заявку из «Счёт отправлен» в «В работе»).
 */
class InboundOrderConfirmationTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_order_confirmation(string $text, bool $expected): void
    {
        $this->assertSame($expected, InboundIntentClassifier::isOrderConfirmationText($text));
    }

    public static function cases(): array
    {
        return [
            'комплектация' => ['Андрей, прошу поставить на комплектацию.', true],
            'в резерв' => ['Поставьте, пожалуйста, в резерв до пятницы', true],
            'отгружайте' => ['Оплатили, отгружайте', true],
            'запускайте' => ['Всё верно, запускайте', true],
            'новая позиция' => ['Добавьте ещё ролик M05324 — 4 шт к этому заказу', false],
            'количество' => ['10 штук', false],
        ];
    }

    /** M-2026-16514: «Бонусную карту ещё прикрепите» после счёта — правка счёта, не новая позиция. */
    #[DataProvider('adjustmentCases')]
    public function test_document_adjustment(string $text, bool $expected): void
    {
        $this->assertSame($expected, InboundIntentClassifier::isDocumentAdjustmentText($text));
    }

    public static function adjustmentCases(): array
    {
        return [
            'бонусная карта' => ["Бонусную карту еще прикрепите пожалуйста.\nКарта: 10000678", true],
            'карта клиента' => ['Карта клиента: 10000678', true],
            'скидка' => ['Можно со скидкой?', true],
            'реквизиты' => ['Поменяйте реквизиты, счёт нужен на ООО «Лифт-Сервис»', true],
            'перевыставить' => ['Перевыставьте счёт, пожалуйста', true],
            'новая позиция' => ['Добавьте ещё ролик M05324 — 4 шт к этому заказу', false],
            'карта контроллера' => ['И ещё нужна плата управления, карта памяти не нужна', false],
        ];
    }
}
