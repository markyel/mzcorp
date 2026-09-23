<?php

namespace Tests\Unit\Services\Mail;

use App\Services\Mail\MessagePersister;
use PHPUnit\Framework\TestCase;

/**
 * Адрес отправителя из кривого заголовка.
 *
 * Кейс M-2026-16937: в поле адреса приехало
 * «=?koi8-r?B?…?=<s.zagudaeva@central-gr.ru» — закодированное имя, слепленное
 * с адресом без пробела и без закрывающей скобки. Заявка выглядела обычной, а
 * письма из неё не уходили: отправлять было некуда.
 */
class CleanAddressTest extends TestCase
{
    public function test_a_plain_address_passes_through(): void
    {
        $this->assertSame(
            ['email' => 's.zagudaeva@central-gr.ru', 'name' => null],
            MessagePersister::cleanAddress('s.zagudaeva@central-gr.ru'),
        );
    }

    public function test_an_encoded_name_glued_to_the_address(): void
    {
        $raw = '=?koi8-r?B?+sHH1cTBxdfBIPPXxdTMwc7BIOHMxcvTwc7E0s/XzsE=?=<s.zagudaeva@central-gr.ru';

        $clean = MessagePersister::cleanAddress($raw);

        $this->assertSame('s.zagudaeva@central-gr.ru', $clean['email']);
        $this->assertNotNull($clean['name'], 'имя из заголовка лучше, чем пусто');
    }

    public function test_the_usual_display_name_form(): void
    {
        $clean = MessagePersister::cleanAddress('Светлана Загудаева <s.zagudaeva@central-gr.ru>');

        $this->assertSame('s.zagudaeva@central-gr.ru', $clean['email']);
        $this->assertSame('Светлана Загудаева', $clean['name']);
    }

    public function test_an_address_buried_in_junk_is_still_found(): void
    {
        $clean = MessagePersister::cleanAddress('"Отдел закупок" ivanov@example.com (по вопросам)');

        $this->assertSame('ivanov@example.com', $clean['email']);
    }

    public function test_what_has_no_address_at_all(): void
    {
        $this->assertNull(MessagePersister::cleanAddress(''));
        $this->assertNull(MessagePersister::cleanAddress('   '));
        $this->assertNull(MessagePersister::cleanAddress('Отдел закупок'));
        $this->assertNull(MessagePersister::cleanAddress('=?koi8-r?B?+sHH1cTB?='));
    }
}
