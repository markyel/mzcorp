<?php

namespace Tests\Unit\Bot;

use App\Enums\RequestStatus;
use App\Models\Request;
use App\Services\Bot\BotDocumentMailer;
use App\Services\Bot\BotRequestNotifier;
use Tests\TestCase;

/** MZ2026-01628 (17.11.2026): бот говорил «отправили на почту», а КП и счёт ушли только файлом в чат. */
class BotDocumentDeliveryTest extends TestCase
{
    private function request(): Request
    {
        $r = new Request();
        $r->internal_code = 'MZ2026-01628';
        $r->client_email = 'lskstovo@rambler.ru';
        $r->setRelation('messengerChat', null);

        return $r;
    }

    public function test_notification_says_where_the_document_really_went(): void
    {
        $r = $this->request();

        $this->assertStringContainsString('отправили его на lskstovo@rambler.ru', BotRequestNotifier::text($r, RequestStatus::Quoted, BotRequestNotifier::VIA_EMAIL));

        $chat = BotRequestNotifier::text($r, RequestStatus::Invoiced, BotRequestNotifier::VIA_CHAT);
        $this->assertStringContainsString('прислали его здесь, в чате', $chat);
        $this->assertStringNotContainsString('lskstovo', $chat);

        $this->assertStringContainsString('на lskstovo@rambler.ru и прислали здесь', BotRequestNotifier::text($r, RequestStatus::Invoiced, BotRequestNotifier::VIA_BOTH));

        $none = BotRequestNotifier::text($r, RequestStatus::Quoted, BotRequestNotifier::VIA_NONE);
        $this->assertSame('📄 По заявке <b>MZ2026-01628</b> готово коммерческое предложение.', $none);

        $this->assertStringContainsString('Ответить можно здесь', BotRequestNotifier::text($r, RequestStatus::AwaitingClientClarification, BotRequestNotifier::VIA_CHAT));
        $this->assertNull(BotRequestNotifier::text($r, RequestStatus::InProgress, BotRequestNotifier::VIA_CHAT));
    }

    public function test_chat_pdf_counts_as_quote_or_invoice_by_name_or_when_fresh(): void
    {
        $old = now()->subHours(5);
        $this->assertTrue(BotRequestNotifier::isDocumentPdf('Счет МЗ-12078 от 2026-11-17_09-19-24.pdf', 'application/pdf', true, $old));
        $this->assertFalse(BotRequestNotifier::isDocumentPdf('Предложение МЗ-373752.pdf', 'application/pdf', true, $old));
        $this->assertTrue(BotRequestNotifier::isDocumentPdf('Предложение МЗ-373752.pdf', 'application/pdf', false, $old));
        $this->assertTrue(BotRequestNotifier::isDocumentPdf('scan_001.pdf', 'application/pdf', true, now()->subMinutes(5)));
        $this->assertFalse(BotRequestNotifier::isDocumentPdf('scan_001.pdf', 'application/pdf', true, $old));
        $this->assertFalse(BotRequestNotifier::isDocumentPdf('photo.jpg', 'image/jpeg', false, now()));
    }

    public function test_email_copy_names_the_request_and_files(): void
    {
        $r = $this->request();
        $files = [['bytes' => '%PDF', 'name' => 'Счет МЗ-12078 от 2026-11-17_09-19-24.pdf', 'mime' => 'application/pdf']];

        $this->assertSame('[MZ2026-01628] Счет МЗ-12078 от 2026-11-17_09-19-24', BotDocumentMailer::subject($r, $files));
        $body = BotDocumentMailer::body($r, 'Доставка Деловыми линиями.', $files);
        $this->assertStringContainsString('Доставка Деловыми линиями.', $body);
        $this->assertStringContainsString('Во вложении — Счет МЗ-12078 от 2026-11-17_09-19-24.pdf по заявке MZ2026-01628', $body);
    }
}
