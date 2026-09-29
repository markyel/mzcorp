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
}
