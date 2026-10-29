<?php

namespace Tests\Unit\QuoteParts;

use App\Livewire\QuoteParts\SupportRequests;
use App\Notifications\QuotePartsConversationNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public function test_a_letter_is_marked_from_its_row_and_the_mark_is_taken_back(): void
    {
        config(['services.quoteparts.token' => 't', 'services.quoteparts.url' => 'https://qp.test']);
        Http::fake([
            'qp.test/api/v1/conversations/*/close' => Http::response(['ok' => true]),
            'qp.test/api/v1/conversations/*/reopen' => Http::response(['ok' => true]),
            'qp.test/api/v1/conversations*' => Http::response(['conversations' => [
                $this->conversation(),
                $this->conversation(['id' => 32, 'subject' => 'Beget welcome', 'status' => 'closed', 'resolution' => 'no_action', 'closed_by' => 'Ольга', 'closed_at' => '2026-10-28T09:00:00+00:00']),
            ]]),
        ]);
        $user = (new \App\Models\User)->forceFill(['id' => 9, 'name' => 'Ольга', 'quoteparts_pilot' => true]);

        $c = Livewire::actingAs($user)->test(SupportRequests::class)->call('load');
        $this->assertStringContainsString('✓ Решено', $c->html());
        $this->assertStringContainsString('🔕 Не требует внимания', $c->html());

        $c->call('close', 31, 'resolved');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/conversations/31/close') && $r['resolution'] === 'resolved' && $r['author_name'] === 'Ольга');

        // Закрытые — с плашкой причины и «Вернуть».
        $html = $c->set('withClosed', true)->html();
        $this->assertStringContainsString('🔕 не требует внимания', $html);
        $this->assertStringContainsString('↩ Вернуть', $html);
        $c->call('reopen', 32);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/conversations/32/reopen'));

        $c->call('close', 31, 'whatever')->assertStatus(422);
    }

    /** Меню шаблонов в панели редактора читает личную библиотеку — пустая таблица в памяти. */
    private function withTemplatesTable(): void
    {
        config(['database.connections.qps_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'database.default' => 'qps_test']);
        \Illuminate\Support\Facades\Schema::create('letter_templates', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->boolean('is_folder')->default(false);
            $t->string('name');
            $t->string('subject')->nullable();
            $t->text('body')->nullable();
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });
    }

    public function test_the_reply_goes_from_the_rich_editor_with_copies_and_pictures_in_the_text(): void
    {
        $this->withTemplatesTable();
        config(['services.quoteparts.token' => 't', 'services.quoteparts.url' => 'https://qp.test', 'app.url' => 'https://mzcorp.test']);
        Storage::fake('local');
        $answered = $this->conversation(['messages' => [
            ['id' => 1, 'direction' => 'in', 'author' => 'Anna', 'text' => 'Не приходит код.', 'at' => '2026-10-27T15:00:00+00:00', 'send_error' => null, 'attachments' => []],
            ['id' => 2, 'direction' => 'out', 'author' => 'Ольга', 'text' => 'Проверьте спам.', 'cc' => ['boss@newparts.example'], 'at' => '2026-10-27T16:00:00+00:00', 'send_error' => null, 'attachments' => []],
            ['id' => 3, 'direction' => 'in', 'author' => 'Anna', 'text' => 'Нет и там.', 'at' => '2026-10-27T17:00:00+00:00', 'send_error' => null, 'attachments' => []],
        ]]);
        Http::fake([
            'qp.test/api/v1/conversations/31/messages' => Http::response(['ok' => true]),
            'qp.test/api/v1/conversations*' => Http::response(['conversations' => [$answered]]),
        ]);
        $user = (new \App\Models\User)->forceFill(['id' => 9, 'name' => 'Ольга', 'quoteparts_pilot' => true]);

        $c = Livewire::actingAs($user)->test(SupportRequests::class)->call('load')->call('open', 31);
        // Копия — как в прошлом нашем ответе.
        $c->assertSet('ccRaw', 'boss@newparts.example');
        $this->assertStringContainsString('mailEditor(', $c->html());
        $this->assertStringContainsString('копия: boss@newparts.example', $c->html());

        // Картинка, загруженная в текст до отправки, лежит в папке черновика.
        $key = $c->get('draftKey');
        Storage::disk('local')->put(\App\Http\Controllers\QuoteParts\SupportInlineImageController::dir(9, $key).'/qpi-abcdef123456.png', 'png');
        $src = 'https://mzcorp.test/dashboard/quoteparts/support/images/'.$key.'/qpi-abcdef123456.png';

        $c->set('bodyHtml', '<p>Вот <b>скрин</b>:</p><img src="'.$src.'"><table><tr><td>a</td></tr></table>')
            ->set('bccRaw', 'Директор <dir@myzip.example>, не адрес')->call('reply')->assertHasErrors('bccRaw');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/messages'));
        $c->set('bccRaw', 'Директор <dir@myzip.example>')->call('reply')->assertHasNoErrors();

        Http::assertSent(function ($r) {
            if (! str_ends_with($r->url(), '/conversations/31/messages')) {
                return false;
            }
            $body = $r->body();

            return str_contains($body, 'boss@newparts.example') && str_contains($body, 'dir@myzip.example')
                && str_contains($body, 'cid:qpi-abcdef123456.png') && ! str_contains($body, '/support/images/')
                && str_contains($body, 'name="inline[]"; filename="qpi-abcdef123456.png"')
                // Таблица — с рамками для почтовиков (OutboundHtmlPostProcessor::emailSafe).
                && str_contains($body, 'border:1px solid')
                && str_contains($body, "Вот скрин:");
        });
        // Черновик после отправки — новый, картинки старого удалены.
        $this->assertNotSame($key, $c->get('draftKey'));
        $this->assertFalse(Storage::disk('local')->exists(\App\Http\Controllers\QuoteParts\SupportInlineImageController::dir(9, $key).'/qpi-abcdef123456.png'));
    }

    public function test_a_russian_reply_to_a_foreign_letter_asks_for_the_translation_first(): void
    {
        $this->withTemplatesTable();
        config(['services.quoteparts.token' => 't', 'services.quoteparts.url' => 'https://qp.test']);
        Http::fake(['qp.test/api/v1/conversations*' => Http::response(['conversations' => [$this->conversation()]])]);
        $user = (new \App\Models\User)->forceFill(['id' => 9, 'name' => 'Ольга', 'quoteparts_pilot' => true]);

        $c = Livewire::actingAs($user)->test(SupportRequests::class)->call('load')->call('open', 31);
        $this->assertStringContainsString('🌐 Перевести на английский', $c->html());
        $c->set('bodyHtml', '<p>Проверьте папку «Спам».</p>')->call('reply')->assertHasErrors('bodyText');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/messages'));
        $c->set('bodyHtml', '<p></p>')->call('reply')->assertHasErrors('bodyText');
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
