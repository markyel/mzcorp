<?php

namespace Tests\Unit\Services\Mail;

use App\Models\EmailMessage;
use App\Services\Mail\EmailTextCleanerService;
use PHPUnit\Framework\TestCase;

/**
 * EmailTextCleanerService::clientOwnText — единый владелец понятия «собственный
 * текст клиента» (2026-09-09). Раньше три детектора считали его по-разному, и
 * «счёт» из цитаты нашего письма давал ложную «просьбу о счёте» (224 ложных
 * override post_sale→client_request за 30 дней на проде).
 */
class EmailTextCleanerClientOwnTextTest extends TestCase
{
    private EmailTextCleanerService $cleaner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleaner = new EmailTextCleanerService;
    }

    private function message(string $plain, string $html = ''): EmailMessage
    {
        $m = new EmailMessage;
        $m->body_plain = $plain;
        $m->body_html = $html;

        return $m;
    }

    public function test_quoted_tail_of_our_letter_is_cut(): void
    {
        $m = $this->message("Добрый день, Андрей. Прошу сообщить когда можно получить заказ?\n\n"
            ."> От кого: Андрей Васюхно <andrey.vasukhno@myzip.ru>\n> Счёт на оплату № 8249 во вложении\n> Прошу выставить счёт");

        $own = $this->cleaner->clientOwnText($m);

        $this->assertStringContainsString('когда можно получить заказ', $own);
        $this->assertStringNotContainsString('выставить счёт', $own);
    }

    public function test_third_party_quote_with_attribution_is_cut_too(): void
    {
        $m = $this->message("Илья, пришло что-то?\n\nВы писали 10 августа 2026 г., 13:41:34:\n> Андрей,\n> Пока не оплачены.");

        $own = $this->cleaner->clientOwnText($m);

        $this->assertStringContainsString('пришло что-то', $own);
        $this->assertStringNotContainsString('не оплачены', $own);
    }

    public function test_forwarded_block_is_kept_with_client_preamble(): void
    {
        $m = $this->message("Прошу выставить счёт по этому КП.\n\n-------- Пересылаемое сообщение --------\nОт: manager@myzip.ru\nТема: КП 359668\n\nКП 359668 во вложении");

        $own = $this->cleaner->clientOwnText($m);

        $this->assertStringContainsString('Прошу выставить счёт', $own);
        $this->assertStringContainsString('359668', $own, 'номер КП из пересланного блока нужен для матча');
    }

    public function test_html_fallback_when_plain_is_broken(): void
    {
        $m = $this->message('', '<html><body><p>Прошу подготовить к отгрузке</p></body></html>');

        $this->assertStringContainsString('подготовить к отгрузке', $this->cleaner->clientOwnText($m));
    }

    public function test_empty_message_gives_empty_string(): void
    {
        $this->assertSame('', $this->cleaner->clientOwnText($this->message('')));
    }
}
