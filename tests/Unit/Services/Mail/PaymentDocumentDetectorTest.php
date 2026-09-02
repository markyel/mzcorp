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

    /**
     * Текст реальной платёжки из кейса M-2026-14395 в том виде, в каком его
     * отдаёт extractTextFromFile. Важная деталь, на которой первая версия
     * парсера сломалась: подпись поля «Назначение платежа» идёт ПОСЛЕ самого
     * назначения — резать текст по ней нельзя.
     */
    private const REAL_PAYMENT_ORDER = <<<'TXT'
        02.09.2026
        Поступ. в банк плат.
        02.09.2026
        Списано со сч. плат.
        0401060
        ПЛАТЕЖНОЕ ПОРУЧЕНИЕ № 36 02.09.2026
        ИНН 1513033606 КПП151301001
        Сумма 16633-18
        Сч. № 40702810806380001628
        ООО "ГРУППА КОМПАНИЙ АКВАРИОН"
        Плательщик
        БИК
        044525411
        Сч. № 30101810145250000411
        ИНН 7715802492 КПП770101001 Сч. № 40702810902540000757
        ООО "МОЙ ЛИФТ"
        Получатель
        Счет на оплату № 8772 от 21 августа 2026. Контроллер эскалатора/траволатора Mitsubishi FX1S-30MR-001
        В т.ч. НДС 22% - 2 999,43 руб.
        Назначение платежа
        Исполнен
        02.09.2026 15:54:14
        TXT;

    public function test_extracts_invoice_number_from_payment_purpose(): void
    {
        $this->assertSame('8772', $this->detector->extractInvoiceNumber(self::REAL_PAYMENT_ORDER));
    }

    public function test_ignores_bank_account_numbers(): void
    {
        // Одна реквизитная часть, без ссылки на счёт: «Сч. № 4070…» — 20 знаков,
        // это расчётный счёт. И сокращённый «Сч.», и длина отсекают кандидата.
        $reqs = "ИНН 7726456781 КПП 772601001 Сч. № 40702810400000123456\nБИК 044525225"
            . "\nСчет № 40702810902540000757";

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
