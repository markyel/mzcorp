<?php

namespace Tests\Unit\Services\DocumentDetector;

use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Prompts\Mail\ClassifyOutboundDocumentPrompt;
use App\Services\Mail\EmailTextCleanerService;
use Tests\TestCase;

/**
 * ClassifyOutboundDocumentPrompt::build — в LLM уходит только СОБСТВЕННЫЙ
 * текст менеджера, без цитаты клиента и без пересланного блока.
 *
 * Регресс M-2026-14608: пересыл письма клиента («Прошу выставить счет…» +
 * «Реквизиты ООО….pdf») классифицировался как invoice по тексту КЛИЕНТА
 * внутри «-------- Перенаправленное сообщение --------».
 */
class ClassifyOutboundDocumentPromptTest extends TestCase
{
    private ClassifyOutboundDocumentPrompt $prompt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prompt = new ClassifyOutboundDocumentPrompt(new EmailTextCleanerService());
    }

    public function test_forwarded_client_block_is_cut_from_body(): void
    {
        $body = "Этой заявки нет  в мз корпе\r\n\r\n\r\n\r\n"
            . "-------- Перенаправленное сообщение --------\r\n"
            . "Тема: \tЗапрос счета для ООО \"Смайнэкс Комфорт\"_0019764\r\n"
            . "Дата: \tFri, 4 Sep 2026 06:43:38 +0000\r\n"
            . "От: \tСеменова Анастасия <semenova_aa@sminex.com>\r\n"
            . "Кому: \t'info@myzip.ru' <info@myzip.ru>\r\n"
            . "\r\n\r\n\r\n"
            . "Добрый день!\r\nПрошу выставить счет для *ООО \"Смайнэкс Комфорт\" *\r\n"
            . "С доставкой по адресу:\r\nул.Академика Королева, д.21\r\n";

        $user = $this->userPrompt($this->message($body, 'Fwd: Запрос счета для ООО "Смайнэкс Комфорт"_0019764'));

        $this->assertStringContainsString('Этой заявки нет', $user);
        $this->assertStringNotContainsString('Прошу выставить счет', $user);
        $this->assertStringNotContainsString('Перенаправленное сообщение', $user);
    }

    public function test_quoted_client_text_is_cut_from_body(): void
    {
        $body = "Добрый день! Уточните, пожалуйста, модель лифта.\n\n"
            . "4 сент. 2026 г., в 09:43, Клиент <client@example.com> написал(а):\n"
            . "> Прошу выставить счет на 5 шт.\n"
            . "> Реквизиты во вложении.\n";

        $user = $this->userPrompt($this->message($body, 'Re: Запрос'));

        $this->assertStringContainsString('Уточните, пожалуйста, модель лифта', $user);
        $this->assertStringNotContainsString('Прошу выставить счет', $user);
    }

    public function test_outlook_header_quote_is_cut_from_body(): void
    {
        $body = "Добрый день!\r\nСчёт во вложении.\r\n\r\n"
            . "От: Клиент <client@example.com>\r\n"
            . "Отправлено: 4 сентября 2026 г. 9:43\r\n"
            . "Кому: info@myzip.ru\r\n"
            . "Тема: Запрос счета\r\n\r\n"
            . "Прошу выставить счет на 5 шт.\r\n";

        $user = $this->userPrompt($this->message($body, 'RE: Запрос счета'));

        $this->assertStringContainsString('Счёт во вложении', $user);
        $this->assertStringNotContainsString('Прошу выставить счет', $user);
    }

    public function test_pure_quote_without_own_text_falls_back_to_raw(): void
    {
        $body = "> Прошу выставить счет на 5 шт.\n> Реквизиты во вложении.\n";

        $user = $this->userPrompt($this->message($body, 'Re: Запрос'));

        $this->assertStringContainsString('Прошу выставить счет', $user);
    }

    public function test_empty_body_falls_back_to_placeholder(): void
    {
        $user = $this->userPrompt($this->message("   \r\n ", 'КП'));

        $this->assertStringContainsString('(пустое тело — только вложения)', $user);
    }

    private function message(string $body, string $subject): EmailMessage
    {
        $m = new EmailMessage();
        $m->subject = $subject;
        $m->body_plain = $body;
        $m->setRelation('attachments', collect());

        return $m;
    }

    private function userPrompt(EmailMessage $m): string
    {
        $r = new Request();
        $r->internal_code = 'M-2026-14608';
        $r->status = RequestStatus::InProgress;
        $r->client_email = 'semenova_aa@sminex.com';

        $messages = $this->prompt->build($m, $r);

        return (string) $messages[1]['content'];
    }
}
