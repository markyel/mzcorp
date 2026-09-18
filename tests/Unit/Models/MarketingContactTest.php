<?php

namespace Tests\Unit\Models;

use App\Models\MarketingContact;
use Tests\TestCase;

/**
 * Записная книжка маркетинга: разбор адресов из формы. Адреса вставляют как
 * придётся — столбцом из Excel, через запятую, вместе с именем в угловых
 * скобках, — поэтому парсер должен вытаскивать их из любого из этих видов.
 */
class MarketingContactTest extends TestCase
{
    public function test_splits_addresses_by_lines_commas_and_spaces(): void
    {
        $this->assertSame(
            ['vslavyanskaya@vdnh.ru', 'ikonovalov@vdnh.ru'],
            MarketingContact::parseEmails("vslavyanskaya@vdnh.ru\nikonovalov@vdnh.ru")
        );
        $this->assertSame(
            ['a@x.ru', 'b@x.ru', 'c@x.ru'],
            MarketingContact::parseEmails('a@x.ru, b@x.ru; c@x.ru')
        );
    }

    public function test_extracts_address_from_named_form(): void
    {
        $this->assertSame(
            ['print2@m-ppk.ru'],
            MarketingContact::parseEmails('Типография МИРАО <print2@m-ppk.ru>')
        );
    }

    public function test_drops_duplicates_ignoring_case(): void
    {
        $this->assertSame(
            ['Print2@m-ppk.ru'],
            MarketingContact::parseEmails("Print2@m-ppk.ru\nprint2@m-ppk.ru")
        );
    }

    public function test_ignores_fragments_without_at_sign(): void
    {
        $this->assertSame(['ok@x.ru'], MarketingContact::parseEmails('телефон +79055425024 ok@x.ru —'));
        $this->assertSame([], MarketingContact::parseEmails('нет адресов'));
        $this->assertSame([], MarketingContact::parseEmails(null));
    }

    public function test_email_helpers_render_stored_list(): void
    {
        $contact = new MarketingContact(['emails' => ['a@x.ru', ' ', 'b@x.ru']]);

        $this->assertSame(['a@x.ru', 'b@x.ru'], $contact->emailList());
        $this->assertSame('a@x.ru, b@x.ru', $contact->emailsJoined());
        $this->assertSame("a@x.ru\nb@x.ru", $contact->emailsText());
    }

    public function test_email_helpers_survive_empty_column(): void
    {
        $contact = new MarketingContact;

        $this->assertSame([], $contact->emailList());
        $this->assertSame('', $contact->emailsJoined());
        $this->assertSame('', $contact->emailsText());
    }
}
