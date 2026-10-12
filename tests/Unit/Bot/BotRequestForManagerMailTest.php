<?php

namespace Tests\Unit\Bot;

use App\Mail\BotRequestForManagerMail;
use App\Models\Request;
use App\Models\RequestItem;
use Tests\TestCase;

/** Письмо менеджеру о заявке из бота: клиент, позиции, текст клиента, ссылка. */
class BotRequestForManagerMailTest extends TestCase
{
    public function test_mail_carries_client_items_text_and_link(): void
    {
        $r = new Request();
        $r->id = 19164;
        $r->internal_code = 'M-2026-19164';
        $r->source = 'telegram';
        $r->client_name = 'Дмитрий';
        $r->client_email = 'client@lift.ru';
        $r->client_phone = '+74951001010';
        $r->client_company = 'ООО «Лифт-Сервис»';
        $r->parsing_meta = ['bot' => ['channel' => 'telegram', 'text' => "M01767 2\nролик Fermator"]];
        $item = new RequestItem(['parsed_name' => 'Ролик замка', 'parsed_article' => 'M01767', 'parsed_qty' => 2, 'parsed_unit' => 'шт.', 'is_active' => true, 'position' => 1]);
        $item->catalog_item_id = 5;
        $r->setRelation('items', collect([$item]));
        $r->setRelation('organization', null);

        $mail = new BotRequestForManagerMail($r);
        $html = $mail->render();

        $this->assertSame('Новая заявка M-2026-19164 из Telegram — ООО «Лифт-Сервис»', $mail->envelope()->subject);
        $this->assertStringContainsString('client@lift.ru (подтверждён кодом)', $html);
        $this->assertStringContainsString('+74951001010', $html);
        $this->assertStringContainsString('Ролик замка', $html);
        $this->assertStringContainsString('в каталоге', $html);
        $this->assertStringContainsString('ролик Fermator', $html);
        $this->assertStringContainsString(route('requests.show', 19164), $html);
    }
}
