<?php

namespace Tests\Unit\Bot;

use App\Mail\BotChatDigestMail;
use App\Models\MessengerMessage;
use App\Models\User;
use App\Services\Bot\BotChatDigestService;
use App\Services\Bot\BotChatSignalService;
use App\Services\Mail\ManagerInboxCopyService;
use App\Services\Mail\SystemNotificationMailer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Оповещение менеджера о сообщениях клиентов в Telegram/MAX: сигнал для тоста
 * (BotChatSignalService) и письмо-сводка (BotChatDigestService) — через 10 минут
 * без прочтения, не чаще раза в 15 минут, об одном сообщении — одно письмо.
 * БД — SQLite в памяти, почта — подставная.
 */
class BotChatNotifyTest extends TestCase
{
    /** @var list<array{to: string, mail: Mailable}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.chat_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'chat_test',
            'services.bot_chat_digest.delay_minutes' => 10,
            'services.bot_chat_digest.min_interval_minutes' => 15,
            'cache.default' => 'array',
        ]);
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->timestamps();
        });
        Schema::create('requests', function (Blueprint $t) {
            $t->id();
            $t->string('internal_code');
            $t->unsignedBigInteger('assigned_user_id')->nullable();
            $t->string('client_name')->nullable();
            $t->string('client_company')->nullable();
            $t->timestamps();
        });
        Schema::create('messenger_chats', function (Blueprint $t) {
            $t->id();
            $t->string('channel', 16);
            $t->string('external_user_id', 64);
            $t->string('chat_id', 64);
            foreach (['username', 'first_name', 'last_name', 'contact_name'] as $c) {
                $t->string($c)->nullable();
            }
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

        DB::table('users')->insert([['id' => 7, 'name' => 'Агрызков Сергей', 'email' => 'sergey@myzip.test'], ['id' => 8, 'name' => 'Другой', 'email' => 'other@myzip.test']]);
        DB::table('requests')->insert([
            ['id' => 101, 'internal_code' => 'M-2026-19180', 'assigned_user_id' => 7, 'client_name' => 'Иван Петров'],
            ['id' => 102, 'internal_code' => 'M-2026-19181', 'assigned_user_id' => 7, 'client_name' => null],
            ['id' => 103, 'internal_code' => 'M-2026-19182', 'assigned_user_id' => 8, 'client_name' => 'Чужой клиент'],
        ]);
        DB::table('messenger_chats')->insert(['id' => 1, 'channel' => 'telegram', 'external_user_id' => '1', 'chat_id' => '1', 'first_name' => 'Ольга']);

        $this->app->instance(SystemNotificationMailer::class, new class($this->sent) extends SystemNotificationMailer
        {
            public function __construct(private array &$sent) {}

            public function sendMailable(string $to, Mailable $mailable, ?string $fromName = null, ?string $messageId = null): bool
            {
                $this->sent[] = ['to' => $to, 'mail' => $mailable];

                return true;
            }
        });
        $this->app->instance(ManagerInboxCopyService::class, new class extends ManagerInboxCopyService
        {
            public function store(User $manager, Mailable $mail, string $messageId, array $artifacts = []): void {}
        });
    }

    private function message(int $requestId, string $text, int $minutesAgo, ?string $readAt = null): int
    {
        return (int) DB::table('messenger_messages')->insertGetId([
            'messenger_chat_id' => 1, 'request_id' => $requestId, 'direction' => 'in', 'kind' => 'client',
            'text' => $text, 'created_at' => now()->subMinutes($minutesAgo), 'read_at' => $readAt,
        ]);
    }

    public function test_no_letter_before_the_delay(): void
    {
        $this->message(101, 'Есть в наличии?', 3);

        $this->assertSame(0, app(BotChatDigestService::class)->run());
        $this->assertSame([], $this->sent);
    }

    public function test_one_letter_with_all_unread_messages_of_the_manager(): void
    {
        $this->message(101, 'Есть в наличии?', 12);
        $this->message(101, 'И срок поставки', 11);
        $this->message(102, 'Пришлите счёт', 2);
        $this->message(103, 'Чужая заявка', 20);

        app(BotChatDigestService::class)->run();

        $mine = array_values(array_filter($this->sent, fn ($s) => $s['to'] === 'sergey@myzip.test'));
        $this->assertCount(1, $mine);
        /** @var BotChatDigestMail $mail */
        $mail = $mine[0]['mail'];
        $this->assertCount(2, $mail->threads);
        $this->assertSame('M-2026-19180', $mail->threads[0]['request']);
        $this->assertSame(['Есть в наличии?', 'И срок поставки'], array_column($mail->threads[0]['messages'], 'text'));
        $this->assertSame('Ольга', $mail->threads[1]['client']);
        $this->assertStringContainsString('chat=1', $mail->threads[0]['url']);
        $this->assertStringContainsString('Ответить в чате', $mail->render());
        $this->assertSame(0, MessengerMessage::query()->where('request_id', '!=', 103)->whereNull('emailed_at')->count());
    }

    public function test_read_messages_do_not_trigger_a_letter(): void
    {
        $this->message(101, 'Есть в наличии?', 30, now()->subMinutes(25)->toDateTimeString());

        $this->assertSame(0, app(BotChatDigestService::class)->run());
    }

    public function test_not_more_often_than_the_interval_and_once_per_message(): void
    {
        $this->message(101, 'Первое', 12);
        app(BotChatDigestService::class)->run();
        $this->assertCount(1, $this->sent);

        // Новое сообщение ждёт 10 минут, но с прошлого письма прошло меньше 15.
        $this->message(101, 'Второе', 11);
        $this->assertSame(0, app(BotChatDigestService::class)->run());

        // Через 15 минут — письмо, в нём оба непрочитанных.
        $this->travel(16)->minutes();
        $this->assertSame(1, app(BotChatDigestService::class)->run());
        $this->assertSame(['Первое', 'Второе'], array_column($this->sent[1]['mail']->threads[0]['messages'], 'text'));

        // Уже упомянутые, но непрочитанные — нового письма не вызывают.
        $this->travel(30)->minutes();
        $this->assertSame(0, app(BotChatDigestService::class)->run());
    }

    public function test_signal_reports_unread_and_fresh_messages_after_the_cursor(): void
    {
        $first = $this->message(101, 'Есть в наличии?', 5);
        $second = $this->message(102, 'Пришлите счёт', 1);
        $this->message(103, 'Чужая заявка', 1);
        $user = User::find(7);

        $initial = app(BotChatSignalService::class)->summary($user, null);
        $this->assertSame(2, $initial['unread']);
        $this->assertSame($second, $initial['cursor']);
        $this->assertSame([], $initial['fresh']);

        $after = app(BotChatSignalService::class)->summary($user, $first);
        $this->assertSame(1, $after['fresh_count']);
        $this->assertSame('M-2026-19181', $after['fresh'][0]['request']);
        $this->assertSame('Ольга', $after['fresh'][0]['client']);
        $this->assertSame('Пришлите счёт', $after['fresh'][0]['text']);

        MessengerMessage::markReadForRequest(102);
        $this->assertSame(1, app(BotChatSignalService::class)->summary($user, null)['unread']);
    }
}
