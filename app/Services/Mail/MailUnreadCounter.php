<?php

namespace App\Services\Mail;

use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Непрочитанные входящие по ящику — одно число и для бейджей почтового
 * клиента (Mail\Client), и для глобального сигнала о новой почте
 * (NewMailSignalService). Кэш общий: прочтение в клиенте сбрасывает его
 * сразу (forget), новые письма догоняют за TTL.
 *
 * Здесь же — фильтры «что в почте mzCorp не показывается»: клиент применяет
 * их и к спискам писем, поэтому правило живёт в одном месте.
 */
class MailUnreadCounter
{
    /**
     * Сколько держим число. Автообновление раз в 30 с не пересчитывает
     * ~700 тыс. входящих у каждого, кто держит почту открытой.
     */
    public const TTL = 60;

    public function __construct(private readonly ImapFolderSyncService $folderSync) {}

    /**
     * Ключ — ящик и ЧЬЯ прочитанность: у личного ящика она владельца, кто бы
     * ни смотрел, поэтому директор, РОП и сам менеджер делят одно число.
     */
    public static function cacheKey(int $mailboxId, int $stateUserId): string
    {
        return 'mail:unread:'.$mailboxId.':'.$stateUserId;
    }

    public function forget(int $mailboxId, int $stateUserId): void
    {
        Cache::forget(self::cacheKey($mailboxId, $stateUserId));
    }

    /** Непрочитанные входящие ящика по прочитанности $stateUserId (из кэша). */
    public function count(int $mailboxId, int $stateUserId): int
    {
        return (int) Cache::remember(
            self::cacheKey($mailboxId, $stateUserId),
            self::TTL,
            fn () => $this->unreadInbound($mailboxId, $stateUserId)->count(),
        );
    }

    /**
     * Непрочитанные входящие ящика. Бейдж ящика = то, что физически лежит в
     * ЭТОМ ящике, поэтому копии здесь не прячем: копия и её оригинал никогда
     * не лежат в одном ящике.
     */
    public function unreadInbound(int $mailboxId, int $stateUserId, bool $withHistory = true): Builder
    {
        $q = $withHistory ? EmailMessage::withHistory() : EmailMessage::query();

        return $q->where('email_messages.mailbox_id', $mailboxId)
            ->where('email_messages.is_draft', false)
            ->where('email_messages.direction', MailDirection::Inbound->value)
            ->tap(fn (Builder $b) => $this->hideReassignedCopies($b))
            ->tap(fn (Builder $b) => $this->hideGoneFromServer($b, [$mailboxId]))
            ->leftJoin('email_message_user_states as ustate', function ($j) use ($stateUserId) {
                $j->on('ustate.email_message_id', '=', 'email_messages.id')
                    ->where('ustate.user_id', '=', $stateUserId);
            })
            ->whereNull('ustate.read_at');
    }

    /**
     * Письма, которых на сервере уже не видно (удалено / в корзине / спаме):
     * в почте mzCorp их тоже нет. Только для ящиков с серверным синком папок
     * (личные с владельцем); общие не трогаем.
     *
     * @param  list<int>  $mailboxIds
     */
    public function hideGoneFromServer(Builder $q, array $mailboxIds): void
    {
        $synced = Mailbox::query()->whereIn('id', $mailboxIds)->get()
            ->filter(fn ($m) => $this->folderSync->isServerSynced($m))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($synced === []) {
            return;
        }
        $q->where(function (Builder $w) use ($synced) {
            $w->whereNotIn('email_messages.mailbox_id', $synced)
                ->orWhere('email_messages.direction', '!=', MailDirection::Inbound->value)
                ->orWhereNotNull('email_messages.imap_uid');
        });
    }

    /**
     * Копии писем переданных заявок: MailReassignArchiverService переложил их
     * в Яндексе в MZ|Reassigned — в почте бывшего менеджера их не показываем.
     */
    public function hideReassignedCopies(Builder $q): void
    {
        $q->whereNotIn('email_messages.folder', MailReassignArchiverService::archivePaths());
    }
}
