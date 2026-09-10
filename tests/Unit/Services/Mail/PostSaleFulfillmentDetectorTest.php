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

    /**
     * Пересылка клиентом собственного запроса другому поставщику (письмо 103750,
     * «есть в наличии … цена и сроки поставки»): Yandex ставит In-Reply-To на его
     * же письмо, `isReply` даёт true — но тред не наш, и «сроки поставки» здесь
     * пресейл, а не вопрос про отгрузку.
     */
    public function test_delivery_terms_in_a_foreign_thread_are_presale(): void
    {
        $body = "Добрый день.\nУ Вас есть в наличии или под заказ кнопка закрытия дверей KM804343 G08 KONE 1 штука.\nЦена и сроки поставки.";

        $foreign = new class extends PostSaleFulfillmentDetector
        {
            protected function repliesToOurThread(\App\Models\EmailMessage $message): bool
            {
                return false;
            }
        };
        $m = $this->message('кнопки', $body);
        $m->in_reply_to = '96931789054287@mail.yandex.ru';

        $this->assertFalse($foreign->deliveryStatusInquiry($m));
    }

    public function test_delivery_terms_in_our_own_thread_stay_post_sale(): void
    {
        $ours = new class extends PostSaleFulfillmentDetector
        {
            protected function repliesToOurThread(\App\Models\EmailMessage $message): bool
            {
                return true;
            }
        };
        $m = $this->message('Re: Счёт 9375', 'Добрый день! Уточните сроки поставки по нашему заказу.');
        $m->in_reply_to = 'our-message@mzcorp.ru';

        $this->assertTrue($ours->deliveryStatusInquiry($m));
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
    // ---- wantsNewInvoiceOrOrder: цитата КП/счёта по выигранной сделке (MailRouter closed_won) ----

    /** @return array<string, array{0:string,1:string,2:bool,3:bool}> subject, own text, isReply, expected */
    public static function citedQuoteCases(): array
    {
        return [
            // Постпродажа: вопрос о сроках/статусе уже оплаченного заказа → false.
            'M-2026-14700 дата готовности' => ['Re: Заказ по КП 359668 — ЗК-2026-0479', 'Прошу уточнить дату готовности заказа по счету: Счет на оплату № 6811', true, false],
            'M-2026-14012 прибытие товара' => ['Re: Счет 6141', 'Здравствуйте! Когда планируется прибытие товара по счету № 6141?', true, false],
            'M-2026-14183 по срокам' => ['Re: КП 361002', 'Дмитрий, по срокам успеваем? Счет № 6272', true, false],
            'M-2026-14235 когда получим' => ['Re: Счёт', 'Здравствуйте, Дмитрий. Когда получим изд-я по счету (во вложении)?', true, false],
            'M-2026-14236 когда забрать' => ['Re: 360912', 'Илья, добрый день. Подскажите пожалуйста, когда можно будет забрать отводку?', true, false],
            'M-2026-14864 заберём завтра' => ['Re: 361500', 'Завтра, 08.09.26 заберем зап.части, которые готовы к выдаче.', true, false],
            'M-2026-13925 досыл' => ['Re: 6012', 'Подскажите пожалуйста, когда будет досыл 14 кнопок по счету 6012?', true, false],
            'M-2026-14346 документы по счёту' => ['Re: 7933', 'Просьба уточнить почему аннулировали документы по счету 7933. Товар нами был получен.', true, false],
            'M-2026-14131 спасибо' => ['Re: КП 361200', 'Ок. Спасибо.', true, false],
            // Продажа: просьба о счёте / дозаказ → true.
            'M-2026-13454 выставить счёт' => ['Re: КП 359001', 'Прошу выставить счет на Артикул: M03779 Блок питания MN9 24В/10А Thyssen — 1шт.', true, true],
            'M-2026-13707 дайте счёт' => ['Re: КП 358800', 'Дайте пожалуйста счет на кабель', true, true],
            'M-2026-14597 нужен счёт' => ['Re: КП 360700', 'Привет Алексей! Нужен счет по этому КП. Можно со скидкой побольше :).', true, true],
            'M-2026-14504 продать ещё' => ['Re: КП 360111', 'Прошу к этим контакторам продать мне 2 дополнительные фронтальные насадки', true, true],
            'M-2026-14008 готовы оплачивать' => ['Re: КП 359900', 'Вот это КП готовы оплачивать. Только добавьте также к остальным канатам', true, true],
            'M-2026-14162 обновить КП' => ['Re: КП 358000', 'Здравствуйте, Илья. Просьба обновить вложенное КП', true, true],
            'M-2026-14850 количество шт' => ['Re: КП 361900', 'Надо исправить: Цепь одна 1 шт. Гребенки только которые в наличии. И добавить доставку.', true, true],
        ];
    }

    /** @dataProvider citedQuoteCases */
    public function test_wants_new_invoice_or_order(string $subject, string $own, bool $isReply, bool $expected): void
    {
        $this->assertSame($expected, $this->detector->wantsNewInvoiceOrOrder($subject, $own, $isReply));
    }
}
