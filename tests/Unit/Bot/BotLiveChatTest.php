<?php

namespace Tests\Unit\Bot;

use App\Enums\MessengerChannel;
use App\Models\MessengerChat;
use App\Models\MessengerMessage;
use App\Models\User;
use App\Services\Bot\BotConversationService;
use App\Services\Bot\BotLiveChatService;
use App\Services\Bot\BotMessenger;
use App\Services\Bot\BotMessengers;
use App\Services\Bot\BotUpdate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Живой чат (12.11.2026): менеджер взял чат бота на себя — бот молчит, сообщения клиента к заявке разговора;
 * через 5 минут тишины или по кнопке чат возвращается боту, клиенту — что разговор закончен. БД — SQLite в памяти.
 */
class BotLiveChatTest extends TestCase
{
    private LiveFakeMessenger $messenger;

    private int $updateNo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.bot_live_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'bot_live_test',
        ]);
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->timestamps();
        });
        Schema::create('messenger_chats', function (Blueprint $t) {
            $t->id();
            $t->string('channel', 16);
            $t->string('external_user_id', 64);
            $t->string('chat_id', 64);
            foreach (['username', 'first_name', 'last_name', 'email', 'contact_name', 'phone', 'company_name', 'inn'] as $c) {
                $t->string($c)->nullable();
            }
            $t->timestamp('email_verified_at')->nullable();
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->text('state')->nullable();
            $t->unsignedInteger('messages_count')->default(0);
            $t->unsignedInteger('requests_count')->default(0);
            $t->timestamp('last_message_at')->nullable();
            $t->timestamp('blocked_at')->nullable();
            $t->unsignedBigInteger('taken_by_user_id')->nullable();
            $t->timestamp('taken_at')->nullable();
            $t->timestamp('taken_activity_at')->nullable();
            $t->timestamps();
        });
        Schema::create('messenger_messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('messenger_chat_id');
            $t->unsignedBigInteger('request_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('direction', 8);
            $t->string('kind', 24);
            $t->text('text')->nullable();
            $t->text('meta')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('emailed_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        $this->messenger = new LiveFakeMessenger;
        $messengers = Mockery::mock(BotMessengers::class);
        $messengers->shouldReceive('for')->andReturn($this->messenger);
        $this->app->instance(BotMessengers::class, $messengers);
    }

    private function client(string $text): string
    {
        $this->messenger->sent = [];
        app(BotConversationService::class)->handle(new BotUpdate(MessengerChannel::Telegram, (string) ++$this->updateNo, '77', '77', 'ivan', 'Иван', null, text: $text));

        return implode("\n---\n", $this->messenger->sent);
    }

    private function chat(): MessengerChat
    {
        return MessengerChat::query()->firstOrFail();
    }

    public function test_the_bot_keeps_quiet_while_a_manager_holds_the_chat(): void
    {
        $this->client('/start');
        $olga = User::create(['name' => 'Ольга Петрова', 'email' => 'olga@myzip.ru']);
        $this->messenger->sent = [];

        $res = app(BotLiveChatService::class)->take($this->chat(), $olga);

        $this->assertTrue($res['ok']);
        $this->assertStringContainsString('На связи Ольга, менеджер MyZip', $this->messenger->sent[0]);
        $this->assertSame('', $this->client('Есть ли ролик 45 мм в наличии?'), 'the bot says nothing');
        $this->assertSame('Есть ли ролик 45 мм в наличии?', MessengerMessage::query()->where('direction', 'in')->latest('id')->value('text'));

        $this->assertTrue(app(BotLiveChatService::class)->send($this->chat()->fresh(), $olga, "Да, 3 шт.\nОтправить КП?")['ok']);
        $this->assertSame("Да, 3 шт.<br>\nОтправить КП?", end($this->messenger->sent));
        $this->assertSame('live', MessengerMessage::query()->latest('id')->value('kind'));
    }

    public function test_after_five_quiet_minutes_the_chat_returns_to_the_bot(): void
    {
        $this->client('/start');
        $olga = User::create(['name' => 'Ольга']);
        app(BotLiveChatService::class)->take($this->chat(), $olga);

        $this->travel(6)->minutes();
        $out = $this->client('Привет');

        $this->assertStringContainsString('Менеджер завершил разговор', $out);
        $this->assertGreaterThan(1, count($this->messenger->sent), 'the bot answers again');
        $this->assertNull($this->chat()->taken_by_user_id);
    }

    public function test_only_the_holder_writes_and_a_colleague_cannot_take_a_held_chat(): void
    {
        $this->client('/start');
        $olga = User::create(['name' => 'Ольга']);
        $ivan = User::create(['name' => 'Иван']);
        $live = app(BotLiveChatService::class);
        $live->take($this->chat(), $olga);

        $this->assertFalse($live->take($this->chat()->fresh(), $ivan)['ok']);
        $this->assertFalse($live->send($this->chat()->fresh(), $ivan, 'Здравствуйте')['ok']);
        $this->travel(6)->minutes();
        $this->assertFalse($live->send($this->chat()->fresh(), $olga, 'Вы тут?')['ok'], 'quiet time is over');
    }

    public function test_idle_chats_are_released_on_schedule_and_live_ones_are_kept(): void
    {
        $this->client('/start');
        $olga = User::create(['name' => 'Ольга']);
        $live = app(BotLiveChatService::class);
        $live->take($this->chat(), $olga);

        $this->travel(3)->minutes();
        $this->assertSame(0, $live->releaseIdle());
        $this->travel(3)->minutes();
        $this->messenger->sent = [];
        $this->assertSame(1, $live->releaseIdle());
        $this->assertStringContainsString('Менеджер завершил разговор', $this->messenger->sent[0]);
        $this->assertNull($live->currentFor($olga));
    }

    public function test_a_message_on_behalf_of_the_bot_does_not_take_the_chat(): void
    {
        $this->client('/start');
        $olga = User::create(['name' => 'Ольга']);

        $this->assertTrue(app(BotLiveChatService::class)->sendAsBot($this->chat(), $olga, 'Мы получили ваш запрос <b>')['ok']);

        $this->assertSame('Мы получили ваш запрос &lt;b&gt;', end($this->messenger->sent));
        $m = MessengerMessage::query()->latest('id')->first();
        $this->assertSame(['bot', $olga->id], [$m->kind, (int) $m->user_id]);
        $this->assertNull($this->chat()->taken_by_user_id);
    }
}

class LiveFakeMessenger implements BotMessenger
{
    /** @var list<string> */
    public array $sent = [];

    public function sendText(string $chatId, string $html, array $buttons = []): bool
    {
        $this->sent[] = $html;

        return true;
    }

    public function sendDocument(string $chatId, string $bytes, string $filename, ?string $captionHtml = null): bool
    {
        return true;
    }

    public function downloadFile(array $file): ?array
    {
        return null;
    }

    public function answerCallback(string $callbackId, ?string $notice = null): void {}

    public function lastSendBlocked(): bool
    {
        return false;
    }

    public function me(): array
    {
        return ['ok' => true, 'username' => 'test_bot', 'message' => 'ok'];
    }

    public function connect(string $url, string $secret): array
    {
        return ['ok' => true, 'message' => 'ok'];
    }

    public function disconnect(string $url): array
    {
        return ['ok' => true, 'message' => 'ok'];
    }
}
