<?php

namespace Tests\Feature\Services\Mail;

use App\Models\EmailMessage;
use App\Services\Mail\ForwardedRequestParser;
use Tests\TestCase;

/**
 * Разбор пересланных заявок (noreply@myzip.ru → реальный отправитель из тела).
 * Feature-тест: нужен config() (forwarder_senders / internal_domains), но БД нет.
 */
class ForwardedRequestParserTest extends TestCase
{
    private ForwardedRequestParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.mail.forwarder_senders', ['noreply@myzip.ru']);
        config()->set('services.mail.internal_domains', ['myzip.ru']);
        $this->parser = new ForwardedRequestParser;
    }

    private function message(string $from, string $body): EmailMessage
    {
        $m = new EmailMessage;
        $m->from_email = $from;
        $m->body_plain = $body;

        return $m;
    }

    public function test_is_forwarded_only_for_configured_senders(): void
    {
        $this->assertTrue($this->parser->isForwarded($this->message('noreply@myzip.ru', 'x')));
        $this->assertTrue($this->parser->isForwarded($this->message('NoReply@MyZip.RU', 'x')));
        $this->assertFalse($this->parser->isForwarded($this->message('client@lemuslift.ru', 'x')));
        $this->assertFalse($this->parser->isForwarded($this->message('', 'x')));
    }

    public function test_extracts_real_sender_from_real_forward_body(): void
    {
        // Реальное тело M-2026-5120 (Thunderbird RU).
        $body = "-------- Перенаправленное сообщение --------\n"
            ."Тема: \tКоммерческое предложение\n"
            ."Дата: \tThu, 18 Jun 2026 16:00:16 +0300\n"
            ."От: \tЛадошин Александр Игоревич <ladoshin@lemuslift.ru>\n"
            ."Отвечать: \tЛадошин Александр Игоревич <ladoshin@lemuslift.ru>\n"
            ."Кому: \tМойЗип <noreply@myzip.ru>\n\n"
            ."Добрый день!\nСориентируйте по стоимости: M02871 — 4 шт.";

        $parsed = $this->parser->parse($this->message('noreply@myzip.ru', $body));

        $this->assertNotNull($parsed);
        $this->assertSame('ladoshin@lemuslift.ru', $parsed['email']);
        $this->assertSame('Ладошин Александр Игоревич', $parsed['name']);
    }

    public function test_picks_from_not_reply_to_or_to(): void
    {
        // «Отвечать:»/«Кому:» не должны перебивать «От:».
        $body = "Кому: \tМойЗип <noreply@myzip.ru>\n"
            ."Отвечать: \tНе тот <wrong@reply.ru>\n"
            ."От: \tНужный <right@client.ru>\n";

        $parsed = $this->parser->parse($this->message('noreply@myzip.ru', $body));

        $this->assertNotNull($parsed);
        $this->assertSame('right@client.ru', $parsed['email']);
    }

    public function test_english_forward_header(): void
    {
        $body = "---------- Forwarded message ---------\n"
            ."From: John Doe <john@acme.com>\n"
            ."Date: Mon, 1 Jun 2026\n"
            ."To: МойЗип <noreply@myzip.ru>\n";

        $parsed = $this->parser->parse($this->message('noreply@myzip.ru', $body));

        $this->assertNotNull($parsed);
        $this->assertSame('john@acme.com', $parsed['email']);
        $this->assertSame('John Doe', $parsed['name']);
    }

    public function test_bare_email_without_brackets(): void
    {
        $parsed = $this->parser->parse($this->message('noreply@myzip.ru', "От: client@external.ru\n"));

        $this->assertNotNull($parsed);
        $this->assertSame('client@external.ru', $parsed['email']);
    }

    public function test_returns_null_without_forward_block(): void
    {
        $parsed = $this->parser->parse($this->message('noreply@myzip.ru', 'Заказ приза. Спасибо!'));

        $this->assertNull($parsed);
    }

    public function test_rejects_internal_sender_in_from_line(): void
    {
        // Если в «От:» оказался наш же домен — не подменяем (не клиент).
        $parsed = $this->parser->parse($this->message('noreply@myzip.ru', "От: Кто-то <staff@myzip.ru>\n"));

        $this->assertNull($parsed);
    }
}
