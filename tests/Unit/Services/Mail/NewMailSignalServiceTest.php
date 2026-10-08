<?php

namespace Tests\Unit\Services\Mail;

use App\Enums\MailboxType;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Mail\MailboxAccessService;
use App\Services\Mail\MailUnreadCounter;
use App\Services\Mail\NewMailSignalService;
use Mockery;
use Tests\TestCase;

/**
 * Сигнал о новой почте идёт только по ЛИЧНЫМ ящикам пользователя — своему и
 * делегированным. Общие info@/order@ и чужие личные (обзор РОПа/директора)
 * — шум, их в сигнал не берём. БД не нужна: доступ к ящикам — мок.
 */
class NewMailSignalServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mailbox(int $id, MailboxType $type, ?int $ownerId): Mailbox
    {
        $m = new Mailbox();
        $m->id = $id;
        $m->type = $type;
        $m->owner_user_id = $ownerId;

        return $m;
    }

    private function user(int $id, bool $hasMailClient): User
    {
        $u = Mockery::mock(User::class)->makePartial();
        $u->id = $id;
        $u->shouldReceive('hasAnyRole')->andReturn($hasMailClient);

        return $u;
    }

    /** @param  list<Mailbox>  $boxes  @param  list<int>  $delegatedIds */
    private function service(array $boxes, array $delegatedIds = []): NewMailSignalService
    {
        $access = Mockery::mock(MailboxAccessService::class);
        $access->shouldReceive('mailboxesFor')->andReturn(collect($boxes));
        $access->shouldReceive('kindOf')->andReturnUsing(
            fn (Mailbox $m) => in_array((int) $m->id, $delegatedIds, true) ? 'delegated' : 'personal'
        );

        return new NewMailSignalService($access, Mockery::mock(MailUnreadCounter::class));
    }

    public function test_only_own_and_delegated_personal_mailboxes(): void
    {
        $own = $this->mailbox(1, MailboxType::Personal, 10);
        $shared = $this->mailbox(2, MailboxType::Shared, null);
        $delegated = $this->mailbox(3, MailboxType::Personal, 20);
        $foreign = $this->mailbox(4, MailboxType::Personal, 30);

        $ids = $this->service([$own, $shared, $delegated, $foreign], delegatedIds: [3])
            ->mailboxesFor($this->user(10, true))
            ->pluck('id')->all();

        $this->assertSame([1, 3], $ids);
    }

    public function test_personal_mailbox_without_owner_is_skipped(): void
    {
        $orphan = $this->mailbox(5, MailboxType::Personal, null);

        $this->assertTrue(
            $this->service([$orphan])->mailboxesFor($this->user(10, true))->isEmpty()
        );
    }

    public function test_disabled_for_roles_without_mail_client(): void
    {
        $own = $this->mailbox(1, MailboxType::Personal, 10);

        $summary = $this->service([$own])->summary($this->user(10, false), null);

        $this->assertFalse($summary['enabled']);
        $this->assertSame(0, $summary['unread']);
        $this->assertSame([], $summary['fresh']);
    }
}
