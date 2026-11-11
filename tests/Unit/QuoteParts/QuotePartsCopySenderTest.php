<?php

namespace Tests\Unit\QuoteParts;

use App\Livewire\Requests\SupplierDispatchPanel;
use App\Models\EmailMessage;
use Tests\TestCase;

/** Копия ответа поставщика от QuoteParts — письмо поставщика, не наше (M-2026-20764, 11.11.2026). */
class QuotePartsCopySenderTest extends TestCase
{
    private static function letter(string $from, string $name, string $direction = 'inbound'): EmailMessage
    {
        return new EmailMessage(['from_email' => $from, 'from_name' => $name, 'direction' => $direction]);
    }

    public function test_a_copy_through_our_relay_is_the_suppliers_letter(): void
    {
        config(['services.quoteparts.offsystem_mailbox' => 'orders@myzip.ru', 'services.quoteparts.platform_mailbox' => 'myzip@quoteparts.net']);

        $this->assertSame('Eve.candice@evelevator.com', SupplierDispatchPanel::quotePartsCopySender(self::letter('orders@myzip.ru', 'Eve.candice@evelevator.com ч ерез orders@')));
        $this->assertSame('ES Spare Parts-Ella', SupplierDispatchPanel::quotePartsCopySender(self::letter('ORDERS@myzip.ru', 'ES Spare Parts-Ella через orders@')));
        $this->assertSame('ru.zip@eastelevator.cn', SupplierDispatchPanel::quotePartsCopySender(self::letter('myzip@quoteparts.net', 'ru.zip@eastelevator.cn через myzip@')));
    }

    public function test_our_own_letters_and_plain_supplier_letters_are_not_copies(): void
    {
        config(['services.quoteparts.offsystem_mailbox' => 'orders@myzip.ru', 'services.quoteparts.platform_mailbox' => 'myzip@quoteparts.net']);

        $this->assertNull(SupplierDispatchPanel::quotePartsCopySender(self::letter('orders@myzip.ru', 'Отдел закупок')));
        $this->assertNull(SupplierDispatchPanel::quotePartsCopySender(self::letter('orders@myzip.ru', 'Eve через orders@', 'outbound')));
        $this->assertNull(SupplierDispatchPanel::quotePartsCopySender(self::letter('steven@oss-elevator-parts.com', 'Steven через WeChat')));
    }
}
