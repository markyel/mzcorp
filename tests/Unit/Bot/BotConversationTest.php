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
            $t->unsignedBigInteger('merged_into_id')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('email_attachments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('email_message_id')->nullable();
            $t->string('filename');
            $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('size_bytes')->default(0);
            $t->string('file_path');
            $t->string('disk')->nullable();
            $t->timestamps();
        });
        Schema::create('outbound_quotes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('request_id');
            $t->unsignedBigInteger('email_attachment_id')->nullable();
            $t->string('document_type');
            $t->string('document_number')->nullable();
            $t->date('document_date')->nullable();
            $t->string('status');
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

    public function test_photos_and_files_go_to_the_draft(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $chat = MessengerChat::create(['channel' => 'telegram', 'external_user_id' => '77', 'chat_id' => '77', 'email' => 'a@b.ru', 'email_verified_at' => now()]);

        $send = function (array $files, string $caption = '', ?string $group = null) {
            $this->messenger->sent = [];
            app(BotConversationService::class)->handle(new BotUpdate(MessengerChannel::Telegram, (string) ++$this->updateNo, '77', '77',
                text: $caption, files: $files, mediaGroupId: $group));

            return implode("\n", array_column($this->messenger->sent, 'html'));
        };

        // Фото с подписью без «Новая заявка» — сразу черновик: подпись — строка, фото — файл.
        $out = $send([['kind' => 'photo', 'ref' => 'p1', 'name' => null, 'mime' => 'image/jpeg', 'size' => 1000]], 'Нужен такой ролик, 2 шт', 'alb');
        $this->assertStringContainsString('Файлов: 1', $out);
        $this->assertStringContainsString('Нужен такой ролик', $out);
        // Второе фото того же альбома — без отдельного ответа.
        $this->assertSame('', $send([['kind' => 'photo', 'ref' => 'p2', 'name' => null, 'mime' => 'image/jpeg', 'size' => 1000]], '', 'alb'));
        $out = $send([['kind' => 'file', 'ref' => 'x', 'name' => 'список.xlsx', 'mime' => 'application/vnd.ms-excel', 'size' => 2000]]);
        $this->assertStringContainsString('Файлов: 3', $out);

        $files = (array) $chat->fresh()->stateGet('draft_files');
        $this->assertCount(3, $files);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($files[0]['path']);
        $this->assertSame('awaiting', array_key_exists('awaiting', (array) $chat->fresh()->state) ? 'awaiting' : null);

        // Слишком большой файл — не берём, объясняем.
        $this->assertStringContainsString('больше 20 МБ', $send([['kind' => 'file', 'ref' => 'big', 'name' => 'видео.mp4', 'mime' => 'video/mp4', 'size' => 50 * 1024 * 1024]]));

        // «Очистить» удаляет и файлы.
        $this->send(callback: 'clear');
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing($files[0]['path']);
        $this->assertSame([], (array) $chat->fresh()->stateGet('draft_files', []));
    }

    public function test_manager_writes_text_and_file_and_sees_blocked_bot(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $activity = Mockery::mock(\App\Services\Request\RequestActivityService::class);
        $activity->shouldReceive('touch');
        $this->app->instance(\App\Services\Request\RequestActivityService::class, $activity);

        $chat = MessengerChat::create(['channel' => 'telegram', 'external_user_id' => '77', 'chat_id' => '77', 'email' => 'a@b.ru', 'email_verified_at' => now()]);
        $rid = \Illuminate\Support\Facades\DB::table('requests')->insertGetId(['internal_code' => 'M-2026-50001', 'messenger_chat_id' => $chat->id, 'client_email' => 'a@b.ru', 'created_at' => now()]);
        $request = Request::find($rid);
        $manager = new \App\Models\User(['name' => 'Агрызков Сергей']);
        $manager->id = 16;
        $svc = app(\App\Services\Bot\BotThreadService::class);

        $res = $svc->fromManager($request, $manager, 'Уточните марку лифта', [['bytes' => 'PDF', 'name' => 'схема.pdf', 'mime' => 'application/pdf']]);
        $this->assertTrue($res['ok'], $res['message']);
        $this->assertStringContainsString('Агрызков Сергей', $this->messenger->sent[0]['html']);
        $this->assertSame('схема.pdf', $this->messenger->documents[0]['filename']);
        $msg = \App\Models\MessengerMessage::query()->where('kind', 'manager')->firstOrFail();
        $this->assertSame('схема.pdf', $msg->meta['attachments'][0]['name']);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($msg->meta['attachments'][0]['path']);
        $this->assertSame($rid, (int) $chat->fresh()->stateGet('active_request_id'));

        // Клиент отключил бота — сообщение не дошло, статус это показывает.
        $this->messenger->blocked = true;
        $res = $svc->fromManager($request, $manager, 'Вы здесь?');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('отключил бота', $res['message']);
        $this->assertTrue(\App\Services\Bot\BotThreadService::presence($chat->fresh())['blocked']);

        // Клиент написал снова — снова на связи.
        $this->travel(1)->minutes();
        \App\Models\MessengerMessage::create(['messenger_chat_id' => $chat->id, 'direction' => 'in', 'kind' => 'text', 'text' => 'да']);
        $p = \App\Services\Bot\BotThreadService::presence($chat->fresh());
        $this->assertFalse($p['blocked']);
        $this->assertTrue($p['active']);
    }

    public function test_reply_after_manager_message_goes_to_him_without_asking(): void
    {
        $chat = MessengerChat::create(['channel' => 'telegram', 'external_user_id' => '77', 'chat_id' => '77', 'email' => 'a@b.ru', 'email_verified_at' => now()]);
        $rid = \Illuminate\Support\Facades\DB::table('requests')->insertGetId(['internal_code' => 'M-2026-50002', 'messenger_chat_id' => $chat->id, 'created_at' => now()]);
        $chat->stateSet('active_request_id', $rid);
        $chat->save();
        \App\Models\MessengerMessage::create(['messenger_chat_id' => $chat->id, 'request_id' => $rid, 'direction' => 'out', 'kind' => 'manager', 'text' => 'Какая марка лифта?']);

        $thread = Mockery::mock(\App\Services\Bot\BotThreadService::class);
        $thread->shouldReceive('fromClient')->once()->withArgs(fn ($c, $r, $text) => $r->id === $rid && $text === 'OTIS GEN2');
        $this->app->instance(\App\Services\Bot\BotThreadService::class, $thread);

        $this->assertStringContainsString('Передали менеджеру', $this->send('OTIS GEN2'));
    }

    /** MZ2026-00881, 10.11.2026: артикулы после КП менеджера уходили ему в старую заявку вместо цены. */
    private function chatInConversation(): array
    {
        $chat = MessengerChat::create(['channel' => 'telegram', 'external_user_id' => '77', 'chat_id' => '77', 'email' => 'a@b.ru', 'email_verified_at' => now()]);
        $rid = \Illuminate\Support\Facades\DB::table('requests')->insertGetId(['internal_code' => 'M-2026-50003', 'messenger_chat_id' => $chat->id, 'created_at' => now()]);
        $chat->stateSet('active_request_id', $rid);
        $chat->save();
        \App\Models\MessengerMessage::create(['messenger_chat_id' => $chat->id, 'request_id' => $rid, 'direction' => 'out', 'kind' => 'manager', 'text' => 'Предложение МЗ-373130', 'created_at' => now()->subMinutes(5)]);

        return [$chat, $rid];
    }

    public function test_articles_during_a_conversation_ask_whether_for_the_request_or_the_price(): void
    {
        [, $rid] = $this->chatInConversation();
        $prices = Mockery::mock(BotPriceService::class);
        $prices->shouldReceive('quote')->andReturn(['found' => true, 'sku' => 'M06258', 'name' => 'Ролик', 'price' => 1500.0, 'for_org' => null, 'stock' => 2.0, 'lead_days' => null, 'catalog_item_id' => 1]);
        $this->app->instance(BotPriceService::class, $prices);
        $thread = Mockery::mock(\App\Services\Bot\BotThreadService::class);
        $thread->shouldNotReceive('fromClient');
        $this->app->instance(\App\Services\Bot\BotThreadService::class, $thread);

        $answer = $this->send('M06258');

        $this->assertStringContainsString('Это к заявке <b>M-2026-50003</b> или узнать цену?', $answer);
        $this->assertStringNotContainsString('Передали менеджеру', $answer);
        $this->assertStringContainsString('Ролик', $this->send('', 'skuprice'));
    }

    public function test_articles_for_the_request_go_to_the_manager_on_the_button(): void
    {
        [, $rid] = $this->chatInConversation();
        $thread = Mockery::mock(\App\Services\Bot\BotThreadService::class);
        $thread->shouldReceive('fromClient')->once()->withArgs(fn ($c, $r, $text) => $r->id === $rid && $text === 'M06258 2');
        $this->app->instance(\App\Services\Bot\BotThreadService::class, $thread);

        $this->send('M06258 2');

        $this->assertStringContainsString('Передали менеджеру', $this->send('', 'tomgr:'.$rid));
    }

    private function chatInReplyMode(int $minutesAgo): int
    {
        $chat = MessengerChat::create(['channel' => 'telegram', 'external_user_id' => '77', 'chat_id' => '77', 'email' => 'a@b.ru', 'email_verified_at' => now()]);
        $rid = \Illuminate\Support\Facades\DB::table('requests')->insertGetId(['internal_code' => 'M-2026-50004', 'messenger_chat_id' => $chat->id, 'created_at' => now()]);
        $chat->stateSet('awaiting', 'reply');
        $chat->stateSet('reply_request_id', $rid);
        $chat->stateSet('reply_at', now()->subMinutes($minutesAgo)->getTimestamp());
        $chat->save();

        return $rid;
    }

    public function test_articles_in_the_reply_mode_ask_too(): void
    {
        $this->chatInReplyMode(5);
        $thread = Mockery::mock(\App\Services\Bot\BotThreadService::class);
        $thread->shouldNotReceive('fromClient');
        $this->app->instance(\App\Services\Bot\BotThreadService::class, $thread);

        $this->assertStringContainsString('Это к заявке <b>M-2026-50004</b>', $this->send('M06258'));
    }

    public function test_the_reply_mode_ends_after_half_an_hour_of_silence(): void
    {
        $rid = $this->chatInReplyMode(BotConversationService::REPLY_IDLE_MINUTES + 1);
        $thread = Mockery::mock(\App\Services\Bot\BotThreadService::class);
        $thread->shouldNotReceive('fromClient');
        $this->app->instance(\App\Services\Bot\BotThreadService::class, $thread);
        \Illuminate\Support\Facades\DB::table('requests')->where('id', $rid)->update(['status' => 'closed_won', 'closed_at' => now()]);

        $answer = $this->send('Нужен ещё ролик на Otis');

        $this->assertStringNotContainsString('Передали менеджеру', $answer);
        $this->assertSame('', (string) $this->chat()->stateGet('awaiting', ''));
    }

    /** «Мои заявки», 11.11.2026: открытые заявки чата и с подтверждённого e-mail, КП ещё раз, запрос счёта. */
    private function chatWithRequests(): array
    {
        $chat = MessengerChat::create(['channel' => 'telegram', 'external_user_id' => '77', 'chat_id' => '77', 'email' => 'a@b.ru', 'email_verified_at' => now()]);
        $db = \Illuminate\Support\Facades\DB::table('requests');
        $ids = [
            'bot' => $db->insertGetId(['internal_code' => 'MZ2026-00001', 'status' => 'assigned', 'messenger_chat_id' => $chat->id, 'created_at' => now()]),
            'mail' => $db->insertGetId(['internal_code' => 'M-2026-50010', 'status' => 'quoted', 'client_email' => 'A@B.ru', 'created_at' => now()]),
            'closed' => $db->insertGetId(['internal_code' => 'M-2026-50011', 'status' => 'closed_won', 'client_email' => 'a@b.ru', 'closed_at' => now(), 'created_at' => now()]),
            'merged' => $db->insertGetId(['internal_code' => 'M-2026-50012', 'status' => 'assigned', 'client_email' => 'a@b.ru', 'merged_into_id' => 1, 'created_at' => now()]),
            'foreign' => $db->insertGetId(['internal_code' => 'M-2026-50013', 'status' => 'quoted', 'client_email' => 'c@d.ru', 'created_at' => now()]),
        ];
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('att/kp.pdf', '%PDF');
        $att = \Illuminate\Support\Facades\DB::table('email_attachments')->insertGetId(['filename' => 'kp.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 4, 'file_path' => 'att/kp.pdf', 'disk' => 'local']);
        \Illuminate\Support\Facades\DB::table('outbound_quotes')->insert(['request_id' => $ids['mail'], 'email_attachment_id' => $att, 'document_type' => 'outbound_quotation_full', 'document_number' => '373130', 'status' => 'matched']);

        return [$chat, $ids];
    }

    public function test_my_requests_lists_open_ones_of_the_chat_and_of_the_email(): void
    {
        $this->chatWithRequests();

        $answer = $this->send('/requests');

        $this->assertStringContainsString('MZ2026-00001', $answer);
        $this->assertStringContainsString('M-2026-50010', $answer);
        $this->assertStringNotContainsString('M-2026-50011', $answer);
        $this->assertStringNotContainsString('M-2026-50012', $answer);
        $this->assertStringNotContainsString('M-2026-50013', $answer);
        $data = array_column(array_merge(...array_merge(...array_column($this->messenger->sent, 'buttons'))), 'data');
        $this->assertContains('kp:2', $data);
        $this->assertContains('askinv:2', $data);
        $this->assertNotContains('kp:1', $data);
        $this->assertNotContains('askinv:1', $data);
    }

    public function test_the_quote_is_sent_again_and_a_foreign_one_is_not(): void
    {
        [, $ids] = $this->chatWithRequests();

        $this->send('', 'kp:'.$ids['mail']);
        $this->assertSame('КП №373130.pdf', $this->messenger->documents[0]['filename'] ?? null);

        $this->messenger->documents = [];
        $this->assertStringContainsString('Заявка не найдена', $this->send('', 'kp:'.$ids['foreign']));
        $this->assertSame([], $this->messenger->documents);
    }

    public function test_asking_for_an_invoice_tells_the_manager_and_awaits_the_invoice(): void
    {
        [$chat, $ids] = $this->chatWithRequests();
        $thread = Mockery::mock(\App\Services\Bot\BotThreadService::class);
        $thread->shouldReceive('fromClient')->once()->withArgs(fn ($c, $r, $text) => $r->id === $ids['mail'] && $text === '🧾 Прошу выставить счёт по КП №373130.');
        $this->app->instance(\App\Services\Bot\BotThreadService::class, $thread);
        $states = Mockery::mock(\App\Services\Request\RequestStateService::class);
        $states->shouldReceive('transitionTo')->once()->withArgs(fn ($r, $to) => $r->id === $ids['mail'] && $to === \App\Enums\RequestStatus::AwaitingInvoice);
        $this->app->instance(\App\Services\Request\RequestStateService::class, $states);

        $this->assertStringContainsString('счёт по заявке <b>M-2026-50010</b> пришлём', $this->send('', 'askinv:'.$ids['mail']));
    }

    public function test_writing_on_a_request_from_the_email_links_it_to_the_chat(): void
    {
        [$chat, $ids] = $this->chatWithRequests();
        \App\Services\Bot\BotThreadService::adopt($chat, Request::query()->findOrFail($ids['mail']));

        $this->assertSame($chat->id, (int) Request::query()->findOrFail($ids['mail'])->messenger_chat_id);
    }

    public function test_the_reply_mode_keeps_going_while_the_client_writes(): void
    {
        $rid = $this->chatInReplyMode(20);
        $thread = Mockery::mock(\App\Services\Bot\BotThreadService::class);
        $thread->shouldReceive('fromClient')->once()->withArgs(fn ($c, $r, $text) => $r->id === $rid);
        $this->app->instance(\App\Services\Bot\BotThreadService::class, $thread);

        $this->assertStringContainsString('Передали менеджеру', $this->send('Нужен ещё ролик на Otis'));
        $this->assertGreaterThan(now()->subMinute()->getTimestamp(), (int) $this->chat()->stateGet('reply_at', 0));
    }

    public function test_menu_ends_the_conversation_with_the_manager(): void
    {
        $this->chatInConversation();
        $thread = Mockery::mock(\App\Services\Bot\BotThreadService::class);
        $thread->shouldNotReceive('fromClient');
        $this->app->instance(\App\Services\Bot\BotThreadService::class, $thread);

        $this->send('', 'menu');

        $this->assertStringNotContainsString('Передали менеджеру', $this->send('Нужен ещё ролик на Otis'));
    }

    public function test_only_articles_tell_a_price_request_from_a_reply(): void
    {
        $this->assertTrue(BotPriceService::onlySkus('M06258'));
        $this->assertTrue(BotPriceService::onlySkus('м04027 5'));
        $this->assertTrue(BotPriceService::onlySkus("M1234, M5678 2 шт.\nM9999 x3"));
        $this->assertFalse(BotPriceService::onlySkus('Добавьте M06258 к заказу'));
        $this->assertFalse(BotPriceService::onlySkus('OTIS GEN2'));
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

        return ! $this->blocked;
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

    public function downloadFile(array $file): ?array
    {
        return ['bytes' => 'BYTES-'.$file['ref'], 'name' => $file['name'] ?? 'photo.jpg', 'mime' => $file['mime'] ?? 'image/jpeg'];
    }

    public function answerCallback(string $callbackId, ?string $notice = null): void {}

    public bool $blocked = false;

    public function lastSendBlocked(): bool
    {
        return $this->blocked;
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
