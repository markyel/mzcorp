<?php

namespace App\Services\Mail;

use App\Enums\MailboxType;
use App\Jobs\Mail\PushImapSeenFlagJob;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\IMAP;

/**
 * Двусторонняя синхронизация прочитанности с почтовым сервером — ТОЛЬКО для
 * владельца личного ящика (по требованию заказчика 2026-09-07):
 *
 *  - владелец открыл письмо в mzCorp → на сервере ставится \Seen
 *    (PushImapSeenFlagJob, STORE +FLAGS в read-write сессии);
 *  - владелец пометил «непрочитано» → на сервере снимается \Seen;
 *  - владелец прочитал письмо в Яндексе → при pull (FETCH FLAGS свежих UID)
 *    письмо становится прочитанным для владельца в mzCorp.
 *
 * Директор/РОП/секретарь, заглядывая в чужой ящик, IMAP не трогают —
 * их прочитанность остаётся персональной (email_message_user_states).
 * Общие ящики (info@) владельца не имеют — не синхронизируются.
 *
 * Исключение из правила CLAUDE.md §8 («\Seen не ставим»): то правило про
 * парсер и синк (чтение без побочных эффектов); здесь \Seen ставится по
 * явному действию владельца ящика, как в обычном почтовом клиенте.
 */
class ImapSeenSyncService
{
    /** Окно pull-синхронизации флагов: письма не старше N дней. */
    public const PULL_DAYS = 14;

    /** Полный проход по всей истории ящика (все письма с imap_uid) — не чаще раза в N часов. */
    public const FULL_PULL_EVERY_HOURS = 24;

    /** Не откатывать «прочитано» в mzCorp, если оно моложе этого (push мог ещё не дойти). */
    private const PULL_UNREAD_GRACE_MINUTES = 10;

    public function __construct(
        private readonly MailboxConnector $connector,
        private readonly MailReadService $read,
    ) {
    }

    /** Пользователь — владелец ЛИЧНОГО ящика (только тогда трогаем IMAP). */
    public function isOwner(Mailbox $mailbox, User $user): bool
    {
        return $mailbox->type === MailboxType::Personal
            && (int) $mailbox->owner_user_id === (int) $user->id;
    }

    /**
     * Поставить/снять \Seen на сервере для писем, если действующий пользователь —
     * владелец их ящика. Группирует по ящику и папке, отправляет job'ы.
     *
     * @param  list<int>  $messageIds
     */
    public function pushSeen(array $messageIds, User $user, bool $seen): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
        if ($ids === []) {
            return;
        }
        $rows = EmailMessage::query()
            ->whereKey($ids)
            ->where('is_draft', false)
            ->whereNotNull('imap_uid')
            ->get(['id', 'mailbox_id', 'folder', 'imap_uid'])
            ->groupBy(fn (EmailMessage $m) => $m->mailbox_id . '|' . $m->folder);

        foreach ($rows as $key => $group) {
            $mailbox = Mailbox::query()->find($group->first()->mailbox_id);
            if (! $mailbox || ! $this->isOwner($mailbox, $user)) {
                continue;
            }
            PushImapSeenFlagJob::dispatch(
                (int) $mailbox->id,
                (string) $group->first()->folder,
                $group->pluck('imap_uid')->map(fn ($u) => (int) $u)->all(),
                $seen,
            );
        }
    }

    /**
     * STORE ±FLAGS \Seen (выполняется в job'е).
     *
     * @param  list<int>  $uids
     */
    public function storeSeen(Mailbox $mailbox, string $folderPath, array $uids, bool $seen): void
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if ($uids === []) {
            return;
        }
        $client = null;
        try {
            $client = $this->connector->imapClient($mailbox);
            $folder = $client->getFolderByPath($folderPath, soft_fail: true);
            if (! $folder) {
                throw new \RuntimeException("Folder {$folderPath} not found");
            }
            $client->openFolder($folder->path, force_select: true);
            $conn = $client->getConnection();
            sort($uids);
            foreach (array_chunk($uids, 200) as $chunk) {
                // STORE принимает set; шлём по одному UID-диапазону через запятую.
                $conn->store(['\Seen'], (int) $chunk[0], null, $seen ? '+' : '-', true, IMAP::ST_UID);
                foreach (array_slice($chunk, 1) as $uid) {
                    $conn->store(['\Seen'], (int) $uid, null, $seen ? '+' : '-', true, IMAP::ST_UID);
                }
            }
            // Зеркалим в нашу копию флагов.
            $messages = EmailMessage::query()->where('mailbox_id', $mailbox->id)->where('folder', $folderPath)->whereIn('imap_uid', $uids)->get(['id', 'imap_flags']);
            foreach ($messages as $m) {
                $flags = array_values(array_filter((array) ($m->imap_flags ?? []), fn ($f) => ! $this->isSeenFlag((string) $f)));
                if ($seen) {
                    $flags[] = '\\Seen';
                }
                $m->forceFill(['imap_flags' => $flags])->saveQuietly();
            }
        } finally {
            $client?->disconnect();
        }
    }

    /**
     * Pull: прочитать FLAGS свежих писем INBOX личного ящика и синхронизировать
     * прочитанность владельца. Возвращает [marked_read, marked_unread].
     *
     * @return array{0:int,1:int}
     */
    public function pullSeen(Mailbox $mailbox, bool $force = false): array
    {
        $owner = $mailbox->owner;
        if ($mailbox->type !== MailboxType::Personal || ! $owner) {
            return [0, 0];
        }
        $fullKey = 'imap-seen-full-pull:' . $mailbox->id;
        $full = $force || ! \Illuminate\Support\Facades\Cache::has($fullKey);
        // INBOX + пользовательские папки, синхронизируемые с сервером
        // (ImapFolderSyncService): письмо, разложенное по папке, лежит там же
        // и на сервере — флаги читаем в его папке.
        $customPaths = \App\Models\MailboxFolder::query()
            ->where('mailbox_id', $mailbox->id)
            ->whereNotNull('imap_path')
            ->whereNotNull('imap_synced_at')
            ->pluck('imap_path')
            ->all();

        $messages = EmailMessage::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('direction', 'inbound')
            ->where('is_draft', false)
            ->whereIn('folder', array_merge(['INBOX'], $customPaths))
            ->whereNotNull('imap_uid')
            // Обычно — окно PULL_DAYS; раз в сутки — вся история ящика в БД
            // (бейдж «Входящие» считает непрочитанные за всё время, и старые
            // письма, прочитанные в Яндексе, иначе висели бы непрочитанными).
            ->when(! $full, fn ($q) => $q->where('sent_at', '>=', now()->subDays(self::PULL_DAYS)))
            ->get(['id', 'imap_uid', 'imap_flags', 'folder']);
        if ($messages->isEmpty()) {
            return [0, 0];
        }

        $client = null;
        $serverSeen = []; // "folder|uid" => bool
        try {
            $client = $this->connector->imapClient($mailbox);
            $conn = $client->getConnection();
            foreach ($messages->groupBy('folder') as $folderPath => $group) {
                $path = $folderPath === 'INBOX' ? $this->connector->findInbox($client)->path : (string) $folderPath;
                try {
                    $client->openFolder($path); // EXAMINE достаточно для FETCH FLAGS
                } catch (\Throwable $e) {
                    Log::info('ImapSeenSyncService: folder not selectable, skipped', ['mailbox_id' => $mailbox->id, 'folder' => $path, 'error' => $e->getMessage()]);

                    continue;
                }
                foreach ($group->pluck('imap_uid')->map(fn ($u) => (int) $u)->chunk(300) as $chunk) {
                    try {
                        $resp = $conn->flags($chunk->values()->all(), IMAP::ST_UID);
                    } catch (\Throwable $e) {
                        // Ни одного UID из набора на сервере нет (письма уехали в
                        // другую папку/удалены) → webklex бросает «Empty response».
                        // Для этого набора просто нет данных — идём дальше.
                        continue;
                    }
                    foreach ((array) $resp->validatedData() as $uid => $flags) {
                        $serverSeen[$folderPath . '|' . (int) $uid] = $this->hasSeen($flags);
                    }
                }
            }
        } finally {
            $client?->disconnect();
        }

        $states = DB::table('email_message_user_states')
            ->where('user_id', $owner->id)
            ->whereIn('email_message_id', $messages->pluck('id'))
            ->pluck('read_at', 'email_message_id');

        $toRead = [];
        $toUnread = [];
        foreach ($messages as $m) {
            $key = $m->folder . '|' . (int) $m->imap_uid;
            if (! array_key_exists($key, $serverSeen)) {
                continue; // письма уже нет в этой папке на сервере
            }
            $readAt = $states[$m->id] ?? null;
            if ($serverSeen[$key] && $readAt === null) {
                $toRead[] = $m->id;
            } elseif (! $serverSeen[$key] && $readAt !== null
                && \Illuminate\Support\Carbon::parse($readAt)->lt(now()->subMinutes(self::PULL_UNREAD_GRACE_MINUTES))) {
                $toUnread[] = $m->id;
            }
        }
        if ($toRead !== []) {
            $this->read->markManyRead($toRead, $owner);
        }
        foreach ($toUnread as $id) {
            $this->read->markUnread($id, $owner);
        }
        if ($toRead !== [] || $toUnread !== []) {
            Log::info('ImapSeenSyncService: pulled \Seen from server', [
                'mailbox_id' => $mailbox->id,
                'owner_user_id' => $owner->id,
                'marked_read' => count($toRead),
                'marked_unread' => count($toUnread),
            ]);
        }

        if ($full) {
            \Illuminate\Support\Facades\Cache::put($fullKey, now()->toIso8601String(), now()->addHours(self::FULL_PULL_EVERY_HOURS));
        }

        return [count($toRead), count($toUnread)];
    }

    private function hasSeen(mixed $flags): bool
    {
        foreach ((array) $flags as $f) {
            if (is_array($f)) {
                if ($this->hasSeen($f)) {
                    return true;
                }
            } elseif ($this->isSeenFlag((string) $f)) {
                return true;
            }
        }

        return false;
    }

    private function isSeenFlag(string $flag): bool
    {
        return strcasecmp(ltrim($flag, '\\'), 'Seen') === 0;
    }
}
