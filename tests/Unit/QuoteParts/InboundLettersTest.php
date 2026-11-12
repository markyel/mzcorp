<?php

namespace Tests\Unit\QuoteParts;

use App\Livewire\QuoteParts\InboundLetters;
use App\Services\QuoteParts\QuotePartsInboundLetterNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** «Письма без запроса»: очередь QuoteParts «Не разобрано» в mzCorp — прочитать, отклонить, колокольчик (без базы). */
class InboundLettersTest extends TestCase
{
    private function letter(array $over = []): array
    {
        return array_merge([
            'id' => 30, 'status' => 'unmatched', 'mailbox' => 'orders@myzip.ru', 'from_email' => 'info@supplier.example', 'from_name' => 'Supplier d.o.o.',
            'subject' => 'Price for sensor', 'sent_at' => '2026-11-10T09:00:00+00:00', 'auto_reply' => false, 'snippet' => 'Dear Sirs, the price is',
            'suppliers' => [], 'tags' => [], 'attachments' => [['index' => 0, 'name' => 'offer.pdf', 'mime' => 'application/pdf', 'size' => 1000, 'stored' => true]],
        ], $over);
    }

    private function fakeQp(array $letters): void
    {
        config(['services.quoteparts.token' => 't', 'services.quoteparts.url' => 'https://qp.test']);
        Http::fake([
            'qp.test/api/v1/inbound-letters/*/ignore' => Http::response(['ok' => true]),
            'qp.test/api/v1/inbound-letters/30' => Http::response($this->letter(['text' => "Dear Sirs, the price is 64,15 EUR.\nBest regards"])),
            'qp.test/api/v1/inbound-letters' => Http::response(['letters' => $letters]),
        ]);
    }

    public function test_a_letter_is_read_with_its_text_and_files(): void
    {
        $this->fakeQp([$this->letter()]);

        $c = Livewire::test(InboundLetters::class);
        $this->assertStringContainsString('Supplier d.o.o.', $c->html());
        $this->assertStringContainsString('Price for sensor', $c->html());

        $html = $c->call('open', 30)->html();
        $this->assertStringContainsString('64,15 EUR', $html);
        $this->assertStringContainsString('offer.pdf', $html);
        $this->assertStringContainsString('/dashboard/quoteparts/letters/30/files/0', $html);
        $this->assertStringNotContainsString('target="_blank" href="'.url('/dashboard/quoteparts/letters'), $html, 'files open in the built-in viewer');
    }

    public function test_a_letter_is_declined(): void
    {
        $this->fakeQp([$this->letter()]);
        $user = new class extends \App\Models\User
        {
            public function hasAnyRole(...$roles): bool
            {
                return true;
            }
        };
        $user->forceFill(['id' => 9, 'name' => 'Ольга']);

        Livewire::actingAs($user)->test(InboundLetters::class)->call('open', 30)->call('ignore', 30)->assertDispatched('qp-letters-changed');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/inbound-letters/30/ignore') && $r->method() === 'POST');
    }

    public function test_procurement_hears_of_a_new_letter_once_and_never_of_an_auto_reply(): void
    {
        $this->fakeQp([$this->letter(['id' => 41, 'auto_reply' => true])]);
        Cache::forget(QuotePartsInboundLetterNotifier::SEEN_CACHE);

        $this->assertSame(0, app(QuotePartsInboundLetterNotifier::class)->notifyNew(), 'auto-reply is not announced');
        $this->assertSame([41], Cache::get(QuotePartsInboundLetterNotifier::SEEN_CACHE));
        $this->assertSame(0, app(QuotePartsInboundLetterNotifier::class)->notifyNew());
    }
}
