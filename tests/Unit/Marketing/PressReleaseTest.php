<?php

namespace Tests\Unit\Marketing;

use App\Models\PressOutlet;
use App\Models\PressRelease;
use App\Services\Marketing\PressMailingService;
use Tests\TestCase;

/**
 * Пресс-релизы: кому уходит письмо и как оно выглядит.
 */
class PressReleaseTest extends TestCase
{
    public function test_release_goes_to_editorial_address_not_sales(): void
    {
        $outlet = new PressOutlet(['emails' => ['sales@pressfeed.ru']]);
        $this->assertNull($outlet->primaryEmail());

        $outlet = new PressOutlet(['emails' => ['adv@1prime.ru', 'website@1prime.ru']]);
        $this->assertSame('website@1prime.ru', $outlet->primaryEmail());

        $outlet = new PressOutlet(['emails' => ['a.kuznetsov@stroygaz.ru', 'info@stroygaz.ru']]);
        $this->assertSame('a.kuznetsov@stroygaz.ru', $outlet->primaryEmail());

        $this->assertNull((new PressOutlet(['emails' => null]))->primaryEmail());
    }

    public function test_outlet_host_drops_www(): void
    {
        $this->assertSame('stroygaz.ru', (new PressOutlet(['url' => 'https://www.stroygaz.ru/']))->host());
    }

    public function test_letter_is_cover_note_then_release(): void
    {
        $release = new PressRelease(['cover_note' => "  Коллеги, новость для отрасли.  ", 'body' => "Заголовок\n\nЛид."]);
        $this->assertSame("Коллеги, новость для отрасли.\n\nЗаголовок\n\nЛид.", app(PressMailingService::class)->letter($release));

        $bare = new PressRelease(['body' => 'Только релиз']);
        $this->assertSame('Только релиз', app(PressMailingService::class)->letter($bare));
    }

    public function test_release_needs_subject_and_body_to_be_sent(): void
    {
        $this->assertFalse((new PressRelease(['body' => 'текст']))->isSendable());
        $this->assertFalse((new PressRelease(['subject' => 'Пресс-релиз: x']))->isSendable());
        $this->assertTrue((new PressRelease(['subject' => 'Пресс-релиз: x', 'body' => 'текст']))->isSendable());
    }
}
