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

    public function test_forwarded_kp_with_preamble_keeps_forwarded_number(): void
    {
        $body = "Выставите счёт, пожалуйста.\n\n-------- Пересылаемое сообщение --------\nОт: Агрызков Сергей <s@myzip.ru>\nТема: КП\n\nПредложение МЗ-364274 во вложении\n";
        $m = $this->msg($body, 'Fwd: КП');

        $own = $this->router->ownBodyText($m);

        $this->assertStringContainsString('364274', $own);
        $this->assertTrue($this->router->hasInvoiceIntent((string) $m->subject, $own));
    }

    public function test_intent_from_subject(): void
    {
        $this->assertTrue($this->router->hasInvoiceIntent('Счёт по КП 364274', 'Добрый день'));
        $this->assertFalse($this->router->hasInvoiceIntent('Re: Запрос приводное колесо', 'Есть ли в наличии?'));
    }
}
