<?php

namespace Tests\Unit\QuoteParts;

use App\Livewire\QuoteParts\SupportRequests;
use App\Notifications\QuotePartsConversationNotification;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Письма на support@quoteparts.net на вкладке «Вопросы»: кто писал, ждёт ли ответа, на каком языке отвечать (без базы). */
class SupportRequestsTest extends TestCase
{
    private function conversation(array $over = []): array
    {
        return array_merge([
            'id' => 31, 'tag' => '[QPC-AB12CD]', 'supplier' => null, 'topic' => 'support', 'subject' => 'Cannot sign in', 'status' => 'open',
            'started_by' => 'supplier', 'contact_email' => 'anna@newparts.example', 'contact_name' => 'Anna', 'last_message_at' => '2026-10-27T15:00:00+00:00',
            'messages' => [
                ['id' => 1, 'direction' => 'in', 'author' => 'Anna', 'text' => 'The code does not come.', 'at' => '2026-10-27T15:00:00+00:00', 'send_error' => null,
                    'attachments' => [['index' => 0, 'name' => 'screen.png', 'mime' => 'image/png', 'size' => 1000]]],
            ],
        ], $over);
    }

    public function test_a_letter_from_someone_who_is_no_supplier_is_listed_with_its_sender(): void
    {
        config(['services.quoteparts.token' => 't', 'services.quoteparts.url' => 'https://qp.test']);
        Http::fake(['qp.test/api/v1/conversations*' => Http::response(['conversations' => [
            $this->conversation(),
            $this->conversation(['id' => 32, 'subject' => 'Old question', 'status' => 'closed']),
        ]])]);

        $c = Livewire::test(SupportRequests::class)->call('load')->call('open', 31);
        $html = $c->html();

        $this->assertStringContainsString('Cannot sign in', $html);
        $this->assertStringContainsString('не поставщик', $html);
        $this->assertStringNotContainsString('🏭 Поставщик', $html, 'no supplier — no supplier badge');
        $this->assertStringContainsString('Anna &lt;anna@newparts.example&gt;', $html);
        $this->assertStringContainsString('ждут ответа: 1', $html);
        $this->assertStringContainsString('The code does not come.', $html);
        $this->assertStringContainsString('screen.png', $html);
        $this->assertStringNotContainsString('Old question', $html, 'closed ones only on request');
        $this->assertStringContainsString('Old question', $c->set('withClosed', true)->html());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'topic=support'));
        // Счётчик вкладки «Поддержка» — ждёт ответа одно открытое письмо.
        $this->assertSame(1, \Illuminate\Support\Facades\Cache::get(SupportRequests::WAITING_CACHE));
    }

    public function test_answer_language_and_waiting(): void
    {
        $c = new SupportRequests;
        $this->assertTrue($c->english($this->conversation()), 'wrote in English — the answer goes in English');
        $this->assertFalse($c->english($this->conversation(['messages' => [['direction' => 'in', 'text' => 'Не приходит код']]])));
        // Поставщик с английской карточкой написал по-русски — отвечаем по-русски, в базу за языком не ходим.
        $this->assertFalse($c->english($this->conversation(['supplier' => 'sup:68', 'messages' => [['direction' => 'in', 'text' => 'Тестовое письмо']]])));
        $this->assertTrue($c->english($this->conversation(['supplier' => 'sup:68'])), 'wrote in English — English even for a supplier');

        $this->assertTrue(SupportRequests::waiting($this->conversation()));
        $answered = $this->conversation(['messages' => [['direction' => 'in', 'text' => 'Hi'], ['direction' => 'out', 'text' => 'Hello']]]);
        $this->assertFalse(SupportRequests::waiting($answered));
    }

    public function test_the_bell_names_the_sender_of_a_support_letter(): void
    {
        $data = (new QuotePartsConversationNotification(['conversation_id' => 31, 'topic' => 'support', 'subject' => 'Cannot sign in',
            'started_by' => 'supplier', 'contact_email' => 'anna@newparts.example', 'contact_name' => null], null))->toDatabase(new \stdClass);

        $this->assertSame('support', $data['topic']);
        $this->assertSame('anna@newparts.example', $data['supplier']);
    }
}
