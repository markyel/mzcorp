<?php

namespace Tests\Unit\Services\Mail;

use App\Models\EmailMessage;
use App\Services\Mail\PostSaleFulfillmentDetector;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit тесты post-sale pre-classifier'а: без БД.
 *
 * Детектор — агрессивный short-circuit (MailCategoryClassifier ставит
 * post_sale мимо LLM), поэтому важно, чтобы он НЕ срабатывал на новых
 * заявках с лексикой комплектации/отгрузки. Регрессии тикетов:
 *  - M-2026-2706 / M-2026-2762 — «прошу поставить на комплектацию» (post_sale);
 *  - «Прошу выставить счёт и поставить на комплектацию: M12243 — 5шт» —
 *    это НОВАЯ заявка, не post_sale.
 */
class PostSaleFulfillmentDetectorTest extends TestCase
{
    private PostSaleFulfillmentDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new PostSaleFulfillmentDetector;
    }

    private function message(string $subject, string $body): EmailMessage
    {
        $m = new EmailMessage;
        $m->subject = $subject;
        $m->body_plain = $body;

        return $m;
    }

    public function test_invoice_request_with_assembly_is_not_post_sale(): void
    {
        // Запрос счёта + комплектация + количество — новая заявка.
        $m = $this->message(
            'Контакт замка CDL',
            "Добрый день.\nПрошу выставить счёт и поставить на комплектацию:\nM12243 - 5шт.\nНа ООО «МЛС Запад»",
        );

        $this->assertNull($this->detector->detect($m));
    }

    public function test_assembly_with_glued_quantity_is_not_post_sale(): void
    {
        // Количество склеено с цифрой («5шт.») — раньше пропускалось мимо ' шт'.
        $m = $this->message('Комплектация', 'Прошу поставить на комплектацию M12243 - 5шт.');

        $this->assertNull($this->detector->detect($m));
    }

    public function test_plain_shipment_request_is_post_sale(): void
    {
        // Чистая отгрузка без счёта/цены/количеств — post_sale.
        $m = $this->message('Отгрузка', 'Прошу отгрузить наш заказ, оплата прошла.');

        $this->assertNotNull($this->detector->detect($m));
    }

    public function test_assembly_request_without_new_order_markers_is_post_sale(): void
    {
        $m = $this->message('Заказ', 'Прошу поставить на комплектацию наш оплаченный заказ.');

        $this->assertNotNull($this->detector->detect($m));
    }

    public function test_unrelated_email_is_not_matched(): void
    {
        $m = $this->message('Запрос цены', 'Пришлите КП на ролики.');

        $this->assertNull($this->detector->detect($m));
    }

    // ---- requestsInvoiceToPay: просьба счёта vs ссылка на выставленный счёт ----

    public function test_invoice_document_reference_with_shipping_question_is_not_invoice_request(): void
    {
        // M-2026-14524 (Liftway): первая строка — реквизит мартовского счёта,
        // сам вопрос — про дату отгрузки. Это постпродажа, не просьба счёта.
        $m = $this->message(
            'Re: 3228 Re: Заказ по КП 350168 — Liftway.ru',
            "Счет на оплату No 3228 от 25 марта 2026\nПрошу сообщить дату отгрузки!",
        );

        $this->assertFalse($this->detector->requestsInvoiceToPay($m));
        $this->assertTrue($this->detector->deliveryStatusInquiry($m));
    }

    public function test_invoice_reference_with_number_sign_and_delivery_question_is_not_invoice_request(): void
    {
        // 1С-шапка «Счет на оплату № 4679 от 04 мая 2026» + «когда остатки ожидаются».
        $m = $this->message('Счет на оплату № 4679 от 04 мая 2026', 'Привет! Когда остатки по счет на оплату № 4679 от 04 мая 2026 ожидаются?');

        $this->assertFalse($this->detector->requestsInvoiceToPay($m));
    }

    public function test_explicit_invoice_request_is_invoice_request(): void
    {
        // ЗИПИС / M00965 — исходный кейс override'а.
        $m = $this->message('Заказ', 'Прошу прислать счёт на оплату и поставить на комплектацию по позиции M00965.');

        $this->assertTrue($this->detector->requestsInvoiceToPay($m));
    }

    public function test_invoice_for_quantity_is_invoice_request(): void
    {
        // esc@interlift.su — «счёт на 12 демпферов».
        $m = $this->message('Демпферы', 'Пришлите счет на 12 демпферов.');

        $this->assertTrue($this->detector->requestsInvoiceToPay($m));
    }

    public function test_reissue_invoice_with_number_is_invoice_request(): void
    {
        // M-2026-13977: просьба ОБНОВИТЬ старый счёт = перевыставить = продажа,
        // хотя в тексте есть ссылка на документ с номером.
        $m = $this->message('Re: Бизнес-ЛИФТ', 'Прошу обновить счёт на оплату № 3674 от 06 апреля 2026. Доставку сделайте СДЭКом.');

        $this->assertTrue($this->detector->requestsInvoiceToPay($m));
    }

    public function test_bare_invoice_for_payment_phrase_is_still_invoice_request(): void
    {
        // Без номера документа фраза «счёт на оплату» остаётся просьбой.
        $m = $this->message('Заказ', 'Нужен счёт на оплату на две платы LCEFOB.');

        $this->assertTrue($this->detector->requestsInvoiceToPay($m));
    }
}
