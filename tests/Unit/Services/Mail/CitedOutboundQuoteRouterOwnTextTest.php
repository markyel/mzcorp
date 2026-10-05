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

    /** M-2026-17776: хвост UUID в имени фото («…4f49dea10304.jpg») — не номер нашего КП. */
    public function test_uuid_photo_name_gives_no_document_number(): void
    {
        $candidates = fn (string $filename): array => $this->candidates($filename);

        $this->assertSame([[], false], $candidates('c7b0d328-3361-47b1-a905-4f49dea10304.jpg'));
        $this->assertSame([[], false], $candidates('IMG_3f9a0c1e4b7d10304a.jpg'));
        $this->assertSame([['355979'], true], $candidates('Предложение МЗ-355979.pdf'));
        $this->assertSame([['10304'], true], $candidates('Счет 10304.jpg'));
    }

    /** M-2026-17687: артикул каталога и номер заявки в теле — не номера наших счетов. */
    public function test_own_codes_in_body_give_no_document_number(): void
    {
        $this->assertSame([[], false], $this->candidates('', 'Просим предоставить СЧЁТ по следующим позициям: M10258'));
        $this->assertSame([[], false], $this->candidates('', 'По заявке M-2026-10317 ждём счёт'));
        $this->assertSame([['10396'], false], $this->candidates('', 'Оплатили счет 10396'));
    }

    /** @return array{0: list<string>, 1: bool} */
    private function candidates(string $filename, string $body = 'Добрый день! Есть ли такой шкив'): array
    {
        $att = new \App\Models\EmailAttachment();
        $att->filename = $filename;
        $m = $this->msg($body, 'запрос');
        $m->setRelation('attachments', collect([$att]));

        return (fn () => $this->collectCandidates($m))->call($this->router);
    }
}
