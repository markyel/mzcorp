<?php

namespace Tests\Unit\Bot;

use App\Enums\MessengerChannel;
use App\Mail\BotVerificationCodeMail;
use App\Models\MessengerChat;
use App\Models\Organization;
use App\Models\Request;
use App\Services\Bot\BotConversationService;
use App\Services\Bot\BotMessenger;
use App\Services\Bot\BotMessengers;
use App\Services\Bot\BotPriceService;
use App\Services\Bot\BotRequestService;
use App\Services\Bot\BotUpdate;
use App\Services\Clients\OrganizationLinkGuard;
use App\Services\Clients\OrganizationRegistryService;
use App\Services\Mail\SystemNotificationMailer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Сквозной диалог бота заявок: регистрация (e-mail с кодом, имя, телефон,
 * ИНН), цена по артикулу, черновик и отправка заявки. Мессенджер, реестр,
 * привязка, цена и создание заявки — подставные; БД — SQLite в памяти.
 */
class BotConversationTest extends TestCase
{
    private FakeBotMessenger $messenger;

    private ?string $sentCode = null;

    private int $updateNo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.bot_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'bot_test',
        ]);
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('inn')->nullable();
            $t->string('pricing_mode')->nullable();
            $t->decimal('discount_percent', 5, 2)->nullable();
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
            $t->timestamp('created_at')->nullable();
        });

        $this->messenger = new FakeBotMessenger();
        $messengers = Mockery::mock(BotMessengers::class);
        $messengers->shouldReceive('for')->andReturn($this->messenger);
        $this->app->instance(BotMessengers::class, $messengers);

        $mailer = Mockery::mock(SystemNotificationMailer::class);
        $mailer->shouldReceive('sendMailable')->andReturnUsing(function ($to, $mail) {
            if ($mail instanceof BotVerificationCodeMail) {
                $this->sentCode = $mail->code;
            }

            return true;
        });
        $this->app->instance(SystemNotificationMailer::class, $mailer);
    }

    private function send(string $text = '', ?string $callback = null, ?string $phone = null): string
    {
        $this->messenger->sent = [];
        $u = new BotUpdate(MessengerChannel::Telegram, (string) ++$this->updateNo, '77', '77', 'ivan', 'Иван', null,
            text: $text, callbackId: $callback !== null ? 'cb'.$this->updateNo : null, callbackData: $callback,
            isStart: $text === '/start', contactPhone: $phone);
        app(BotConversationService::class)->handle($u);

        return implode("\n---\n", array_column($this->messenger->sent, 'html'));
    }

    private function chat(): MessengerChat
    {
        return MessengerChat::query()->firstOrFail();
    }

    public function test_registration_price_draft_and_submit(): void
    {
        $org = Organization::create(['name' => 'ООО «Лифт-Сервис»', 'inn' => '7701234567']);
        $registry = Mockery::mock(OrganizationRegistryService::class);
        $registry->shouldReceive('resolveForIngest')->with('7701234567')->andReturn(['status' => 'ok', 'org' => $org, 'created' => false]);
        $this->app->instance(OrganizationRegistryService::class, $registry);
        $guard = Mockery::mock(OrganizationLinkGuard::class);
        $guard->shouldReceive('link')->once()->withArgs(fn ($o, $email, $ctx) => $o->id === $org->id && $email === 'ivan@lift.ru' && ($ctx['self_declared'] ?? false))
            ->andReturn(['status' => 'pending', 'requests_linked' => 0]);
        $this->app->instance(OrganizationLinkGuard::class, $guard);
        $prices = Mockery::mock(BotPriceService::class);
        $prices->shouldReceive('quote')->andReturn(['found' => true, 'sku' => 'M12345', 'name' => 'Ролик', 'price' => 1500.0, 'for_org' => null, 'stock' => 2.0, 'lead_days' => null, 'catalog_item_id' => 1]);
        $this->app->instance(BotPriceService::class, $prices);
        $requests = Mockery::mock(BotRequestService::class);
        $requests->shouldReceive('submit')->once()->andReturnUsing(function (MessengerChat $chat) {
            $this->assertSame(['M12345', null], array_column((array) $chat->stateGet('draft'), 'sku'));
            $r = new Request();
            $r->internal_code = 'M-2026-20001';

            return $r;
        });
        $this->app->instance(BotRequestService::class, $requests);

        $this->assertStringContainsString('рабочий e-mail', $this->send('/start'));
        // Артикул до регистрации — запоминается, цена придёт после неё.
        $this->assertStringContainsString('после регистрации', $this->send('M12345'));
        $this->assertStringContainsString('после регистрации', $this->send('M12345'));
        $this->assertStringContainsString('Не похоже на e-mail', $this->send('ivan'));
        $this->assertStringContainsString('Отправили код', $this->send('Ivan@Lift.ru'));
        $this->assertNotNull($this->sentCode);

        $wrong = $this->sentCode === '000000' ? '111111' : '000000';
        $this->assertStringContainsString('Код не подошёл', $this->send($wrong));
        $this->assertStringContainsString('Как к вам обращаться', $this->send($this->sentCode));
        $this->assertTrue($this->chat()->isVerified());

        $this->assertStringContainsString('телефон', $this->send('Иван Петров'));
        $this->assertTrue(collect($this->messenger->lastButtons())->flatten(1)->contains(fn ($b) => ! empty($b['contact'])));
        $this->assertStringContainsString('ИНН', $this->send('8 (900) 123-45-67'));
        $this->assertSame('+79001234567', $this->chat()->phone);

        $this->assertStringContainsString('ООО «Лифт-Сервис»', html_entity_decode($this->send('7701234567')));
        $after = html_entity_decode($this->send(callback: 'org_yes'));
        $this->assertStringContainsString('подтвердит менеджер', $after);
        $this->assertStringContainsString('1 500,00 ₽', $after);   // отложенная цена после регистрации
        $chat = $this->chat();
        $this->assertTrue($chat->isRegistered());
        $this->assertNull($chat->organization_id);

        $this->assertStringContainsString('Добавили', $this->send(callback: 'add:M12345'));
        $this->assertStringContainsString('ролик Fermator', $this->send('ролик Fermator 2 шт'));
        $done = $this->send(callback: 'send');
        $this->assertStringContainsString('M-2026-20001', $done);
        $this->assertSame([], (array) $this->chat()->stateGet('draft', []));
    }

    public function test_buttons_before_registration_lead_back_to_it(): void
    {
        $this->send('/start');
        $this->assertStringContainsString('рабочий e-mail', $this->send(callback: 'req'));
    }

    public function test_quantity_correction_replaces_line(): void
    {
        $chat = MessengerChat::create(['channel' => 'telegram', 'external_user_id' => '77', 'chat_id' => '77',
            'email' => 'a@b.ru', 'email_verified_at' => now(), 'contact_name' => 'А', 'phone' => '+70000000000', 'company_name' => 'ИП']);
        $this->send(callback: 'req');
        $this->send("M12345 2\nM23456 1, M34567 4");
        $this->send('M12345 5');
        $draft = (array) $chat->fresh()->stateGet('draft');
        $this->assertSame([['M12345', 5.0], ['M23456', 1.0], ['M34567', 4.0]], array_map(fn ($l) => [$l['sku'], (float) $l['qty']], $draft));
    }
}

class FakeBotMessenger implements BotMessenger
{
    /** @var list<array{chat: string, html: string, buttons: array}> */
    public array $sent = [];

    public function sendText(string $chatId, string $html, array $buttons = []): bool
    {
        $this->sent[] = ['chat' => $chatId, 'html' => $html, 'buttons' => $buttons];

        return true;
    }

    public function lastButtons(): array
    {
        return end($this->sent)['buttons'] ?? [];
    }

    public function answerCallback(string $callbackId, ?string $notice = null): void {}

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
