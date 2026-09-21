<?php

namespace Tests\Unit\Services\Clients;

use App\Models\ClientDiscount;
use Tests\TestCase;

/**
 * Разбор выгрузки скидок. Главное здесь — ИНН: Excel съедает ведущий ноль, и
 * без восстановления 18 контрагентов из 853 не сопоставятся ни с одной
 * организацией, то есть молча останутся без скидки. Без БД и без сети.
 */
class ClientDiscountImportTest extends TestCase
{
    public function test_leading_zero_of_inn_is_restored(): void
    {
        // «0276088789» приезжает из Excel как число 276088789.
        $this->assertSame('0276088789', ClientDiscount::normalizeInn('276088789'));
        $this->assertSame('0276088789', ClientDiscount::normalizeInn(' 0276088789 '));
    }

    public function test_normal_inn_is_left_alone(): void
    {
        $this->assertSame('9722058736', ClientDiscount::normalizeInn('9722058736'));
        $this->assertSame('770123456789', ClientDiscount::normalizeInn('770123456789'));
    }

    public function test_separators_are_stripped(): void
    {
        $this->assertSame('9722058736', ClientDiscount::normalizeInn('9722-058-736'));
        $this->assertSame('9722058736', ClientDiscount::normalizeInn('ИНН 9722058736'));
    }

    public function test_garbage_is_refused(): void
    {
        // Лучше отбросить строку, чем привязать скидку к чужому контрагенту.
        $this->assertNull(ClientDiscount::normalizeInn(''));
        $this->assertNull(ClientDiscount::normalizeInn('не указан'));
        $this->assertNull(ClientDiscount::normalizeInn('12345'));
    }
}
