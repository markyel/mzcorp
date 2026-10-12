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

        Schema::create('requests', function (Blueprint $t) {
            $t->id();
            $t->string('internal_code');
            $t->string('status')->default('assigned');
            $t->unsignedBigInteger('messenger_chat_id')->nullable();
            $t->unsignedBigInteger('assigned_user_id')->nullable();
            $t->string('client_name')->nullable();
            $t->string('client_email')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
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
        // Артикул до подтверждения — запоминается, цена придёт сразу после кода.
        $this->assertStringContainsString('после подтверждения', $this->send('Есть в наличии? Артикул: M12345'));
        $this->assertStringContainsString('Не похоже на e-mail', $this->send('ivan'));
        $this->assertStringContainsString('Отправили код', $this->send('Ivan@Lift.ru'));
        $this->assertNotNull($this->sentCode);

        $wrong = $this->sentCode === '000000' ? '111111' : '000000';
        $this->assertStringContainsString('Код не подошёл', $this->send($wrong));
        // Больше ничего не спрашиваем: после кода — сразу цена.
        $afterCode = html_entity_decode($this->send($this->sentCode));
        $this->assertStringContainsString('E-mail подтверждён', $afterCode);
        $this->assertStringContainsString('1 500,00 ₽', $afterCode);
        $this->assertStringNotContainsString('обращаться', $afterCode);
        $this->assertTrue($this->chat()->isVerified());

        $this->assertStringContainsString('Добавили', $this->send(callback: 'add:M12345'));
        $this->assertStringContainsString('ролик Fermator', $this->send('ролик Fermator 2 шт'));

        // Первая заявка — анкета, каждый вопрос можно пропустить.
        $this->assertStringContainsString('Как к вам обращаться', $this->send(callback: 'send'));
        $this->assertStringContainsString('Телефон', $this->send('Иван Петров'));
        $this->assertTrue(collect($this->messenger->lastButtons())->flatten(1)->contains(fn ($b) => ! empty($b['contact'])));
        // В Telegram кнопка «Пропустить» рядом с «Отправить номер» приходит текстом.
        $this->assertStringContainsString('ИНН', $this->send('Пропустить'));
        $this->assertNull($this->chat()->phone);

        $this->assertStringContainsString('ООО «Лифт-Сервис»', html_entity_decode($this->send('7701234567')));
        $done = html_entity_decode($this->send(callback: 'org_yes'));
        $this->assertStringContainsString('подтвердит менеджер', $done);
        $this->assertStringContainsString('M-2026-20001', $done);
        $chat = $this->chat();
        $this->assertSame('Иван Петров', $chat->contact_name);
        $this->assertNull($chat->organization_id);
        $this->assertSame([], (array) $chat->stateGet('draft', []));
    }

    public function test_second_request_does_not_ask_again_and_skip_all_sends_at_once(): void
    {
        $requests = Mockery::mock(BotRequestService::class);
        $requests->shouldReceive('submit')->twice()->andReturnUsing(function () {
            $r = new Request();
            $r->internal_code = 'M-2026-30001';

            return $r;
        });
        $this->app->instance(BotRequestService::class, $requests);
        MessengerChat::create(['channel' => 'telegram', 'external_user_id' => '77', 'chat_id' => '77', 'email' => 'a@b.ru', 'email_verified_at' => now()]);

        $this->send(callback: 'req');
        $this->send('ролик');
        $this->assertStringContainsString('обращаться', $this->send(callback: 'send'));
        $this->assertStringContainsString('M-2026-30001', $this->send(callback: 'skip_all'));

        $this->send(callback: 'req');
        $this->send('ещё ролик');
        $this->assertStringContainsString('M-2026-30001', $this->send(callback: 'send'));
    }

    public function test_free_text_goes_to_manager_of_open_request(): void
    {
        $chat = MessengerChat::create(['channel' => 'telegram', 'external_user_id' => '77', 'chat_id' => '77', 'email' => 'a@b.ru', 'email_verified_at' => now()]);
        $mine = \Illuminate\Support\Facades\DB::table('requests')->insertGetId(['internal_code' => 'M-2026-40001', 'messenger_chat_id' => $chat->id, 'created_at' => now()]);
        $foreign = \Illuminate\Support\Facades\DB::table('requests')->insertGetId(['internal_code' => 'M-2026-40002', 'messenger_chat_id' => 999, 'created_at' => now()]);

        $thread = Mockery::mock(\App\Services\Bot\BotThreadService::class);
        $thread->shouldReceive('fromClient')->twice()->withArgs(fn ($c, $r, $text) => $r->id === $mine && in_array($text, ['Когда отгрузка?', 'Оплатили вчера'], true));
        $this->app->instance(\App\Services\Bot\BotThreadService::class, $thread);

        $this->assertStringContainsString('Куда отправить', $this->send('Когда отгрузка?'));
        $this->assertTrue(collect($this->messenger->lastButtons())->flatten(1)->contains(fn ($b) => ($b['data'] ?? '') === 'tomgr:'.$mine));
        $this->assertStringContainsString('Передали менеджеру', $this->send(callback: 'tomgr:'.$mine));

        // Кнопка «Ответить» под уведомлением — следующее сообщение уходит менеджеру.
        $this->assertStringContainsString('M-2026-40001', $this->send(callback: 'reply:'.$mine));
        $this->assertStringContainsString('Передали менеджеру', $this->send('Оплатили вчера'));

        // Чужая заявка — нельзя.
        $this->assertStringNotContainsString('Напишите сообщение', $this->send(callback: 'reply:'.$foreign));
    }

    public function test_status_notification_texts(): void
    {
        $r = new Request();
        $r->internal_code = 'M-2026-19164';
        $r->client_email = 'a@b.ru';
        $this->assertStringContainsString('коммерческое предложение', \App\Services\Bot\BotRequestNotifier::text($r, \App\Enums\RequestStatus::Quoted));
        $this->assertStringContainsString('счёт', \App\Services\Bot\BotRequestNotifier::text($r, \App\Enums\RequestStatus::Invoiced));
        $this->assertNull(\App\Services\Bot\BotRequestNotifier::text($r, \App\Enums\RequestStatus::InProgress));
    }

    public function test_buttons_before_verification_lead_back_to_email(): void
    {
        $this->send('/start');
        $this->assertStringContainsString('рабочий e-mail', $this->send(callback: 'req'));
    }

    public function test_quantity_correction_replaces_line(): void
    {
        $chat = MessengerChat::create(['channel' => 'telegram', 'external_user_id' => '77', 'chat_id' => '77',
            'email' => 'a@b.ru', 'email_verified_at' => now()]);
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

    /** @var list<array{chat: string, filename: string, caption: ?string}> */
    public array $documents = [];

    public function sendDocument(string $chatId, string $bytes, string $filename, ?string $captionHtml = null): bool
    {
        $this->documents[] = ['chat' => $chatId, 'filename' => $filename, 'caption' => $captionHtml];

        return true;
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
