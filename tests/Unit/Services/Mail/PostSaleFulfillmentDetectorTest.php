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
}
