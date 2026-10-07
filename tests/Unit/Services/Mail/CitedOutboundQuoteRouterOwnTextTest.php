<?php

namespace Tests\Unit\Services\Mail;

use App\Models\EmailMessage;
use App\Services\Mail\CitedOutboundQuoteRouter;
use App\Services\Mail\EmailTextCleanerService;
use PHPUnit\Framework\TestCase;

/**
 * Цитируемый КП ищем только в СОБСТВЕННОМ тексте клиента; «ждёт счёт» — только
 * при просьбе о счёте/оплате в его словах. Регресс M-2026-12166.
 */
class CitedOutboundQuoteRouterOwnTextTest extends TestCase
{
    private CitedOutboundQuoteRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new CitedOutboundQuoteRouter(new EmailTextCleanerService());
    }

    private function msg(string $body, string $subject = 'Re: Запрос'): EmailMessage
    {
        $m = new EmailMessage();
        $m->subject = $subject;
        $m->body_plain = $body;

        return $m;
    }

    public function test_quoted_reminder_with_kp_number_is_not_client_text(): void
    {
        $body = "Добрый день!\nПодскажите а с резьбой М10мм есть?\n\n> Понедельник, 24 августа 2026, 00:01 +03:00 от Агрызков Сергей <sergey.agryzkov@myzip.ru>:\n> Мы отправляли вам коммерческое предложение *364274* от 17.08.2026 по заявке *M-2026-12166*.\n> Подскажите, готовы ли вы перейти к выставлению счёта.\n";
        $m = $this->msg($body);

        $own = $this->router->ownBodyText($m);

        $this->assertStringContainsString('резьбой М10мм', $own);
        $this->assertStringNotContainsString('364274', $own);
        $this->assertFalse($this->router->hasInvoiceIntent((string) $m->subject, $own));
    }

    public function test_explicit_invoice_request_in_own_text(): void
    {
        $body = "Прошу выставить счёт по КП 364274.\n\n12.08.2026 10:00, Иван <ivan@myzip.ru> пишет:\n> текст нашего письма\n";
        $m = $this->msg($body);

        $own = $this->router->ownBodyText($m);

        $this->assertStringContainsString('364274', $own);
        $this->assertTrue($this->router->hasInvoiceIntent((string) $m->subject, $own));
    }

    public function test_forwarded_kp_with_preamble_keeps_invoice_intent(): void
    {
        // Номер КП для привязки берётся из всего тела (collectCandidates);
        // собственный текст — преамбула клиента, а намерение — в ней.
        $body = "Выставите счёт, пожалуйста.\n\n-------- Пересылаемое сообщение --------\nОт: Агрызков Сергей <s@myzip.ru>\nТема: КП\n\nПредложение МЗ-364274 во вложении\n";
        $m = $this->msg($body, 'Fwd: КП');

        $own = $this->router->ownBodyText($m);

        $this->assertStringContainsString('Выставите счёт', $own);
        $this->assertTrue($this->router->hasInvoiceIntent((string) $m->subject, $own));
    }

    public function test_intent_from_subject(): void
    {
        $this->assertTrue($this->router->hasInvoiceIntent('Счёт по КП 364274', 'Добрый день'));
        $this->assertFalse($this->router->hasInvoiceIntent('Re: Запрос приводное колесо', 'Есть ли в наличии?'));
    }

    /** M-2026-17776: картинки не смотрим вовсе; имя не-картинки — только наш префикс «МЗ-N». */
    public function test_attachment_names_count_only_with_our_prefix_and_never_for_images(): void
    {
        $this->assertSame([[], false], $this->candidates('c7b0d328-3361-47b1-a905-4f49dea10304.jpg', 'Добрый день', 'image/jpeg'));
        $this->assertSame([[], false], $this->candidates('Счет 10304.jpg', 'Добрый день', 'image/jpeg'));
        $this->assertSame([[], false], $this->candidates('Счет МЗ-10304.jpg', 'Добрый день', 'image/jpeg'));
        $this->assertSame([['355979'], true], $this->candidates('Предложение МЗ-355979 от 2026-10-07_12-21-48.pdf', 'Добрый день', 'application/pdf'));
        $this->assertSame([[], false], $this->candidates('Счет 10304.pdf', 'Добрый день', 'application/pdf'));
        $this->assertSame([[], false], $this->candidates('Заявка 123456.xlsx', 'Добрый день', 'application/vnd.ms-excel'));
    }

    /** M-2026-17687: артикул каталога и номер заявки — не номера наших счетов; явная ссылка — да. */
    public function test_only_explicit_document_references_count(): void
    {
        $this->assertSame([[], false], $this->candidates('', 'Просим предоставить СЧЁТ по следующим позициям: M10258'));
        $this->assertSame([[], false], $this->candidates('', 'По заявке M-2026-10317 ждём счёт'));
        $this->assertSame([['10396'], false], $this->candidates('', 'Оплатили счет 10396'));
        $this->assertSame([['10396'], false], $this->candidates('', 'по счету №10396 от 01.10.2026 г. оплата прошла'));
        $this->assertSame([['364274'], false], $this->candidates('', 'Коммерческое предложение № 364274 от 12.08.2026 согласовано'));
        $this->assertSame([['10551'], false], $this->candidates('', "Счет МЗ-10551 от 2026-10-07_12-20-43
-- С уважением"));
        $this->assertSame([['10432'], false], $this->candidates('', 'please send proforma invoice 10432 signed'));

        // Голое число — не сигнал, даже рядом со словом «счёт» в другом смысле.
        $this->assertSame([[], false], $this->candidates('', 'Нужен счет на сумму 123456 руб, индекс 357600, Россия'));
        $this->assertSame([[], false], $this->candidates('', 'Прошу выставить счет. Наш заказ 10432 готов?'));
        $this->assertSame([[], false], $this->candidates('', 'Россия, 357600, Ставропольский край — выставите счёт'));
    }

    /** Тема: наши темы несут номер в любом месте; в теле — только строка «Тема:/Subject:» цитаты. */
    public function test_subject_numbers_anywhere_and_quoted_subject_line(): void
    {
        $this->assertSame([['369647'], false], $this->candidatesWithSubject('Re: 369647 Re: Заявка на ролик', 'Готовы заказать'));
        $this->assertSame([['369796'], false], $this->candidatesWithSubject('Re: [369796] Запрос', 'ок'));
        $this->assertSame([['366639'], false], $this->candidatesWithSubject('Re: Фотобарьер (366639)', 'Пришлите счет на тот, что в наличии'));
        $this->assertSame([['360560'], false], $this->candidatesWithSubject('Re: 1399/ 360560', 'пришлите счет на 1 шт Карта 20006175'));
        // Артикул и ссылка в теме вырезаются, как и в теле.
        $this->assertSame([[], false], $this->candidatesWithSubject('Re: Выключатель ВП73-10432', 'ок'));

        $this->assertSame([['364531'], false], $this->candidates('', "Счет во вложении?

From: Дмитрий
Subject: Re: Заказ Жемчужная Плаза/ 364531/ 8728
Счет во вложении."));
        $this->assertSame([['368531'], false], $this->candidates('', "Выставите счёт

> От: Агрызков
> Тема: 368531 Re: Заявка
"));
        // Число в произвольной строке цитаты — не сигнал.
        $this->assertSame([[], false], $this->candidates('', "ок

> Карта: 10000982
> Бонус 365145 начислен"));
    }

    /** Цитата нашего напоминания: «коммерческое предложение *364657* от …» — звёздочки не мешают. */
    public function test_our_reminder_quote_is_an_explicit_reference(): void
    {
        $body = "Прошу выставить счет.

> Здравствуйте, Николай!
> Мы отправляли вам коммерческое предложение *364657* от 19.08.2026 по заявке *M-2026-12659* (4 дн. назад).
";
        $this->assertSame([['364657'], false], $this->candidates('', $body));
        $this->assertSame([['366118'], false], $this->candidates('', "Чем отличаются?

02.09.2026, Илья: Добрый день! КП366118 во вложении"));
    }

    /** M-2026-18326: «ВП73-10432» — артикул, а 10432 — номер нашего КП по чужой заявке. */
    public function test_article_with_alnum_prefix_is_not_a_document_number(): void
    {
        $body = "Прошу прислать счет с доставкой в Спб\nВыключатель ВП73-10432 00 УХЛЗ с продольным роликом - 5 шт";
        $this->assertSame([[], false], $this->candidates('', $body));

        $this->assertSame([[], false], $this->candidates('', 'Счёт по КС00-002365 оплатили'));
        $this->assertSame([[], false], $this->candidates('', 'Нужен FAA24-350BL2 1 шт, выставите счёт'));

        // Чисто буквенный префикс — это наши документы.
        $this->assertSame([['364274'], false], $this->candidates('', 'Выставите счёт по КП МЗ-364274'));
        // «21-10432» — не артикул, но и не номер нашего документа (число не стоит сразу за словом).
        $this->assertSame([[], false], $this->candidates('', 'счёт 21-10432 оплачен'));
    }

    /** Числа в ссылках (слаг каталога, ysclid) — не номера наших документов. */
    public function test_numbers_inside_urls_are_ignored(): void
    {
        $body = "Нужен выключатель, счёт прошу\nhttps://snab-lift.ru/catalog/mikropereklyuchatel-vp-73-21-10432.html?ysclid=muxu0q33is125164145\nСпасибо";
        $this->assertSame([[], false], $this->candidates('', $body));

        $this->assertSame([['10432'], false], $this->candidates('', "Счёт 10432 оплатим\nhttp://example.com/x-10433"));
    }

    /** @return array{0: list<string>, 1: bool} */
    private function candidates(string $filename, string $body = 'Добрый день! Есть ли такой шкив', string $mime = 'application/pdf'): array
    {
        $m = $this->msg($body, 'запрос');
        $atts = [];
        if ($filename !== '') {
            $att = new \App\Models\EmailAttachment();
            $att->filename = $filename;
            $att->mime_type = $mime;
            $atts[] = $att;
        }
        $m->setRelation('attachments', collect($atts));

        return (fn () => $this->collectCandidates($m))->call($this->router);
    }

    /** @return array{0: list<string>, 1: bool} */
    private function candidatesWithSubject(string $subject, string $body): array
    {
        $m = $this->msg($body, $subject);
        $m->setRelation('attachments', collect([]));

        return (fn () => $this->collectCandidates($m))->call($this->router);
    }
}
