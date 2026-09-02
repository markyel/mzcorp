<?php

namespace Tests\Unit\Services\Mail;

use App\Services\Mail\PaymentDocumentDetector;
use Tests\TestCase;

/**
 * Извлечение номера счёта из платёжного поручения — БД не трогаем.
 *
 * Главный риск здесь: платёжка нашпигована длинными номерами (р/с, к/с, БИК,
 * ИНН) и своим собственным номером («ПЛАТЕЖНОЕ ПОРУЧЕНИЕ № 36»). Матчиться
 * можно только по ссылке на счёт из назначения платежа — иначе письмо
 * прицепится к чужой сделке. Эталон — платёжка из кейса M-2026-14395.
 */
class PaymentDocumentDetectorTest extends TestCase
{
    private PaymentDocumentDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = app(PaymentDocumentDetector::class);
    }

    /** Реальная платёжка клиента (кейс M-2026-14395, счёт 8772). */
    private const REAL_PAYMENT_ORDER = <<<'TXT'
        ПЛАТЕЖНОЕ ПОРУЧЕНИЕ № 36
        02.09.2026
        Сумма 16633-18
        ИНН 7726456781 КПП 772601001 Сч. № 40702810400000123456
        ООО "ГРУППА КОМПАНИЙ АКВАРИОН"
        Плательщик
        БИК 044525225 Сч. № 30101810400000000225
        Банк плательщика
        ИНН 7743123456 КПП 774301001 Сч. № 40702810900000000123
        ООО "МОЙ ЛИФТ"
        Получатель
        Назначение платежа
        Счет на оплату № 8772 от 21 августа 2026. Контроллер эскалатора/траволатора
        Mitsubishi FX1S-30MR-001. В том числе НДС 20%
        TXT;

    public function test_extracts_invoice_number_from_payment_purpose(): void
    {
        $this->assertSame('8772', $this->detector->extractInvoiceNumber(self::REAL_PAYMENT_ORDER));
    }

    public function test_ignores_bank_account_numbers(): void
    {
        // Без назначения платежа остаётся только реквизитная часть — «Сч. №
        // 40702810400000123456». 20 знаков — это расчётный счёт, не наш номер.
        $reqs = "ИНН 7726456781 КПП 772601001 Сч. № 40702810400000123456\nБИК 044525225";

        $this->assertNull($this->detector->extractInvoiceNumber($reqs));
    }

    public function test_ignores_own_payment_order_number(): void
    {
        // «ПЛАТЕЖНОЕ ПОРУЧЕНИЕ № 36» — номер самой платёжки, не счёта.
        $this->assertNotSame('36', $this->detector->extractInvoiceNumber(self::REAL_PAYMENT_ORDER));
    }

    public function test_extracts_number_with_prefix(): void
    {
        $text = "Назначение платежа\nОплата по счету № НФ00-005513 от 12.08.2026";

        $this->assertSame('НФ00-005513', $this->detector->extractInvoiceNumber($text));
    }

    public function test_returns_null_when_no_invoice_reference(): void
    {
        $text = "Назначение платежа\nПеревод собственных средств. Без НДС";

        $this->assertNull($this->detector->extractInvoiceNumber($text));
    }
}
