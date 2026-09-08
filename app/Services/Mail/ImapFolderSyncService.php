<?php

namespace App\Services\Mail;

use App\Enums\MailboxType;
use App\Jobs\Mail\PushImapFolderOpJob;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\MailboxFolder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;

/**
 * Двусторонняя синхронизация пользовательских папок почтового клиента с
 * IMAP-сервером — только для ЛИЧНЫХ ящиков с владельцем (заказчик, 2026-09-08:
 * «синхронизировать структуру папок в почте менеджера и расположение письма»).
 *
 *  pull (из mail:sync, SyncImapFoldersJob):
 *    - LIST → дерево пользовательских папок сервера ↔ mailbox_folders
 *      (imap_path = raw-путь из LIST, MUTF-7). Системные папки (INBOX, Sent,
 *      Drafts, Spam, Trash …) и служебные деревья MyLift (MZ|…) не трогаем.
 *    - по каждой синхронизируемой папке: UID-список сервера ↔ письма БД с
 *      folder = imap_path. Новые UID → FETCH заголовков → матч по Message-ID
 *      с письмом ящика (обычно оно лежит у нас как INBOX) → переезжает в папку
 *      (folder/imap_uid/mailbox_folder_id). Письма, которых у нас нет вовсе,
 *      НЕ импортируем (история до подключения ящика — не заводить заявки).
 *  push (PushImapFolderOpJob): создание/переименование/удаление папки и
 *    UID MOVE писем — по действиям пользователя в mzCorp. Локальное состояние
 *    меняется сразу (оптимистично), job подтверждает на сервере, следующий
 *    pull сверяет.
 *
 * Общие ящики (info@ и т.п.) остаются на папках только в mzCorp.
 */
class ImapFolderSyncService
{
    /** Не дёргать CREATE повторно чаще, чем раз в N минут, если сервер так и не подтвердил папку. */
    private const RECREATE_AFTER_MINUTES = 10;

    public function __construct(
        private readonly MailboxConnector $connector,
        private readonly MailFolderRouter $router,
    ) {
    }

    /** Ящик синхронизирует папки с сервером? Личный, с владельцем, активный, фича включена. */
    public function isServerSynced(Mailbox $mailbox): bool
    {
        return (bool) config('services.mail.folder_sync_enabled', true)
            && $mailbox->is_active
            && $mailbox->type === MailboxType::Personal
            && $mailbox->owner_user_id !== null;
    }

    public function delimiter(): string
    {
        $d = (string) config('services.mail.folder_sync_delimiter', '|');

        return $d !== '' ? $d : '|';
    }

    /* ============================ PULL ============================ */

    /**
     * @return array{folders_created:int, folders_deleted:int, moved:int, unknown:int, gone:int}
     */
    public function pull(Mailbox $mailbox): array
    {
        $stats = ['folders_created' => 0, 'folders_deleted' => 0, 'moved' => 0, 'unknown' => 0, 'gone' => 0];
        if (! $this->isServerSynced($mailbox)) {
            return $stats;
        }

        $client = null;
        try {
            $client = $this->connector->imapClient($mailbox);
            $conn = $client->getConnection();
            $list = (array) $conn->folders('', '*')->validatedData();
            $delimiter = $this->delimiterFromList($list) ?? $this->delimiter();

            $server = self::customFoldersFromList(
                $list,
                $delimiter,
                (array) config('services.mail.folder_sync_system_roots', []),
                (array) config('services.mail.folder_sync_excluded_prefixes', []),
            );

            $stats = array_merge($stats, $this->reconcileFolders($mailbox, $server, $delimiter));
            $stats = array_merge($stats, $this->reconcileMessages($mailbox, $client, array_keys($server)));
        } finally {
            $client?->disconnect();
        }

        if ($stats['folders_created'] || $stats['folders_deleted'] || $stats['moved'] || $stats['gone']) {
            Log::info('ImapFolderSyncService: pulled folders from server', ['mailbox_id' => $mailbox->id] + $stats);
        }

        return $stats;
    }

    /**
     * Пользовательские папки из сырого LIST (path => ['delimiter','flags']):
     * без системных корней (и их потомков), без служебных префиксов, без
     * \Noselect и special-use.
     *
     * @param  array<string, array{delimiter?:string, flags?:array}>  $list
     * @param  list<string>  $systemRoots
     * @param  list<string>  $excludedPrefixes
     * @return array<string, array{path:string, name:string, parent:?string, depth:int}>  отсортировано по глубине, потом по пути
     */
    public static function customFoldersFromList(array $list, string $delimiter, array $systemRoots, array $excludedPrefixes): array
    {
        $specialUse = ['sent', 'drafts', 'trash', 'junk', 'archive', 'all', 'flagged', 'important', 'inbox'];
        $rootsLower = array_map(fn ($r) => mb_strtolower(trim((string) $r)), $systemRoots);
        $prefixesLower = array_map(fn ($p) => mb_strtolower(trim((string) $p)), $excludedPrefixes);

        // Пути со special-use флагами — тоже системные корни (вместе с потомками).
        $flaggedRoots = [];
        foreach ($list as $path => $info) {
            foreach ((array) ($info['flags'] ?? []) as $flag) {
                $f = strtolower(ltrim((string) $flag, '\\'));
                if (in_array($f, $specialUse, true)) {
                    $flaggedRoots[] = (string) $path;
                    break;
                }
            }
        }

        $out = [];
        foreach ($list as $path => $info) {
            $path = (string) $path;
            $flags = array_map(fn ($f) => strtolower(ltrim((string) $f, '\\')), (array) ($info['flags'] ?? []));
            if (in_array('noselect', $flags, true) || in_array('nonexistent', $flags, true)) {
                continue;
            }
            $segments = $delimiter !== '' ? explode($delimiter, $path) : [$path];
            $rootDecoded = mb_strtolower(MailboxFolder::displayNameFromImapPath($segments[0], $delimiter));
            $rootRaw = mb_strtolower($segments[0]);
            if (in_array($rootRaw, $rootsLower, true) || in_array($rootDecoded, $rootsLower, true)
                || in_array($rootRaw, $prefixesLower, true) || in_array($rootDecoded, $prefixesLower, true)) {
                continue;
            }
            $underFlagged = false;
            foreach ($flaggedRoots as $fr) {
                if ($path === $fr || str_starts_with($path, $fr . $delimiter)) {
                    $underFlagged = true;
                    break;
                }
            }
            if ($underFlagged) {
                continue;
            }
            $depth = count($segments) - 1;
            $out[$path] = [
                'path' => $path,
                'name' => MailboxFolder::displayNameFromImapPath($path, $delimiter),
                'parent' => $depth > 0 ? implode($delimiter, array_slice($segments, 0, -1)) : null,
                'depth' => $depth,
            ];
        }

        uasort($out, fn ($a, $b) => [$a['depth'], $a['path']] <=> [$b['depth'], $b['path']]);

        return $out;
    }

    /**
     * Сервер → mailbox_folders: создать недостающие, принять («усыновить»)
     * локальные папки без imap_path с тем же именем на том же уровне, удалить
     * локальные, которых на сервере больше нет, и дослать на сервер локальные,
     * ещё не созданные там.
     *
     * @param  array<string, array{path:string,name:string,parent:?string,depth:int}>  $server
     * @return array{folders_created:int, folders_deleted:int}
     */
    private function reconcileFolders(Mailbox $mailbox, array $server, string $delimiter): array
    {
        $created = 0;
        $deleted = 0;

        $local = MailboxFolder::query()->where('mailbox_id', $mailbox->id)->get();
        $byPath = $local->whereNotNull('imap_path')->keyBy('imap_path');

        foreach ($server as $path => $f) {
            if ($byPath->has($path)) {
                $folder = $byPath[$path];
                $dirty = [];
                if ($folder->imap_synced_at === null) {
                    $dirty['imap_synced_at'] = now();
                }
                // Имя на сервере — истина (переименовали в Яндексе).
                if ($folder->name !== $f['name'] && $f['name'] !== '') {
                    $dirty['name'] = mb_substr($f['name'], 0, MailboxFolder::NAME_MAX);
                }
                $parentId = $f['parent'] !== null ? ($byPath[$f['parent']]->id ?? null) : null;
                if ((int) $folder->parent_id !== (int) $parentId && ($f['parent'] === null || $parentId !== null)) {
                    $dirty['parent_id'] = $parentId;
                }
                if ($dirty !== []) {
                    $folder->forceFill($dirty)->save();
                }

                continue;
            }

            $parentId = $f['parent'] !== null ? ($byPath[$f['parent']]->id ?? null) : null;
            if ($f['parent'] !== null && $parentId === null) {
                continue; // родитель не синхронизируется (системный/служебный) — пропускаем ветку
            }

            // «Усыновить» локальную папку без imap_path с тем же именем и родителем
            // (создана до включения синка или в этот же момент из mzCorp).
            $adopt = $local->first(fn (MailboxFolder $l) => $l->imap_path === null
                && (int) $l->parent_id === (int) $parentId
                && mb_strtolower($l->name) === mb_strtolower($f['name']));
            if ($adopt) {
                $adopt->forceFill(['imap_path' => $path, 'imap_synced_at' => now()])->save();
                $byPath[$path] = $adopt;

                continue;
            }

            $position = (int) MailboxFolder::query()
                ->where('mailbox_id', $mailbox->id)->where('parent_id', $parentId)->max('position') + 1;
            $new = MailboxFolder::create([
                'mailbox_id' => $mailbox->id,
                'parent_id' => $parentId,
                'name' => mb_substr($f['name'], 0, MailboxFolder::NAME_MAX),
                'imap_path' => $path,
                'imap_synced_at' => now(),
                'position' => $position,
                'created_by_user_id' => null,
            ]);
            $byPath[$path] = $new;
            $created++;
        }

        // Локальные папки с imap_path, которых на сервере нет.
        foreach ($local->whereNotNull('imap_path') as $folder) {
            if (isset($server[$folder->imap_path])) {
                continue;
            }
            if ($folder->imap_synced_at !== null) {
                // Сервер её знал → удалили в Яндексе. Письма — во «Входящие», подпапки — выше.
                DB::transaction(function () use ($folder) {
                    EmailMessage::query()->where('mailbox_folder_id', $folder->id)->update(['mailbox_folder_id' => null]);
                    MailboxFolder::query()->where('parent_id', $folder->id)->update(['parent_id' => $folder->parent_id]);
                    $folder->delete();
                });
                $deleted++;
            } elseif ($folder->updated_at === null || $folder->updated_at->lt(now()->subMinutes(self::RECREATE_AFTER_MINUTES))) {
                // Мы поставили путь, но CREATE на сервере не подтвердился — повторить.
                $folder->touch();
                PushImapFolderOpJob::dispatch($mailbox->id, 'create', ['folder_id' => $folder->id]);
            }
        }

        // Локальные папки без imap_path (созданы до включения синка) — завести на сервере.
        foreach ($local->whereNull('imap_path') as $folder) {
            if ($folder->imap_path !== null) {
                continue; // усыновлена выше
            }
            $path = $this->pathFor($folder->fresh(), $delimiter);
            if ($path === null) {
                continue;
            }
            $folder->forceFill(['imap_path' => $path])->save();
            PushImapFolderOpJob::dispatch($mailbox->id, 'create', ['folder_id' => $folder->id]);
        }

        return ['folders_created' => $created, 'folders_deleted' => $deleted];
    }

    /**
     * По каждой синхронизируемой папке: UID сервера ↔ письма БД.
     *
     * @param  list<string>  $serverPaths
     * @return array{moved:int, unknown:int, gone:int}
     */
    private function reconcileMessages(Mailbox $mailbox, Client $client, array $serverPaths): array
    {
        $moved = 0;
        $unknown = 0;
        $gone = 0;
        $conn = $client->getConnection();

        $folders = MailboxFolder::query()
            ->where('mailbox_id', $mailbox->id)
            ->whereIn('imap_path', $serverPaths)
            ->whereNotNull('imap_synced_at')
            ->get();

        foreach ($folders as $folder) {
            try {
                $client->openFolder($folder->imap_path, force_select: true);
                $serverUids = array_map('intval', (array) $conn->getUid()->validatedData());
            } catch (\Throwable $e) {
                Log::warning('ImapFolderSyncService: cannot list folder', [
                    'mailbox_id' => $mailbox->id, 'folder' => $folder->imap_path, 'error' => $e->getMessage(),
                ]);

                continue;
            }
            $serverSet = array_fill_keys($serverUids, true);

            $dbRows = EmailMessage::query()
                ->where('mailbox_id', $mailbox->id)
                ->where('folder', $folder->imap_path)
                ->whereNotNull('imap_uid')
                ->get(['id', 'imap_uid', 'mailbox_folder_id']);
            $dbSet = [];
            foreach ($dbRows as $r) {
                $dbSet[(int) $r->imap_uid] = $r;
            }

            // Новые для нас UID в папке → заголовки → матч по Message-ID.
            $newUids = array_values(array_filter($serverUids, fn ($u) => ! isset($dbSet[$u])));
            foreach (array_chunk($newUids, 50) as $chunk) {
                $mids = $this->fetchMessageIds($client, $chunk);
                foreach ($mids as $uid => $mid) {
                    if ($mid === null) {
                        $unknown++;

                        continue;
                    }
                    $row = $this->findByMessageId($mailbox, $mid);
                    if (! $row) {
                        $unknown++;

                        continue;
                    }
                    if ($this->rehome($row, $folder->imap_path, (int) $uid, $folder->id)) {
                        $moved++;
                    }
                }
            }

            // Письма, которые по БД лежат в папке, а на сервере их там нет:
            // уехали в другую папку (подхватит её проход) либо во «Входящие»
            // (подхватит INBOX-синк через MessagePersister::rehomeIfFiled) либо
            // удалены. UID больше не валиден — обнуляем, чтобы не гонять флаги.
            foreach ($dbRows as $r) {
                if (! isset($serverSet[(int) $r->imap_uid])) {
                    EmailMessage::query()->whereKey($r->id)->update(['imap_uid' => null]);
                    $gone++;
                }
            }
        }

        return ['moved' => $moved, 'unknown' => $unknown, 'gone' => $gone];
    }

    /**
     * FETCH RFC822.HEADER для набора UID (папка уже выбрана) → uid => Message-ID
     * (без угловых скобок, как в email_messages.message_id) либо null.
     *
     * @param  list<int>  $uids
     * @return array<int, ?string>
     */
    public function fetchMessageIds(Client $client, array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $out = [];
        try {
            $resp = $client->getConnection()->headers($uids, 'RFC822', IMAP::ST_UID)->validatedData();
        } catch (\Throwable $e) {
            Log::warning('ImapFolderSyncService: FETCH headers failed', ['uids' => count($uids), 'error' => $e->getMessage()]);

            return array_fill_keys($uids, null);
        }
        $rows = is_array($resp) ? $resp : [];
        // Для одного UID webklex отдаёт строку, а не [uid => строка].
        if (count($uids) === 1 && ! isset($rows[$uids[0]])) {
            $rows = [$uids[0] => is_array($resp) ? implode("\n", array_map('strval', $resp)) : (string) $resp];
        }
        foreach ($uids as $uid) {
            $hdr = $rows[$uid] ?? null;
            if (is_array($hdr)) {
                $hdr = implode("\n", array_map('strval', $hdr));
            }
            $out[$uid] = self::messageIdFromHeaders((string) $hdr);
        }

        return $out;
    }

    /** Message-ID из сырых заголовков (без <>), либо null. */
    public static function messageIdFromHeaders(string $headers): ?string
    {
        // Заголовок может быть свёрнут (fold) на следующую строку.
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $headers) ?? $headers;
        if (! preg_match('/^Message-ID:\s*(.+?)\s*$/mi', $unfolded, $m)) {
            return null;
        }
        $mid = trim($m[1]);
        if (preg_match('/<([^>]+)>/', $mid, $mm)) {
            $mid = $mm[1];
        }
        $mid = trim($mid, " \t<>");

        return $mid !== '' ? $mid : null;
    }

    /** Письмо ящика по Message-ID: сначала то, что лежит в INBOX/Sent, потом любое. */
    private function findByMessageId(Mailbox $mailbox, string $mid): ?EmailMessage
    {
        return EmailMessage::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('is_draft', false)
            ->whereRaw('lower(message_id) = ?', [mb_strtolower($mid)])
            ->orderByRaw("CASE folder WHEN 'INBOX' THEN 0 WHEN 'Sent' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->first();
    }

    /**
     * Переселить запись письма на новое серверное место. Возвращает false, если
     * уникальный ключ (mailbox_id, folder, message_id) уже занят другой записью
     * (тогда лишь проставляем папку, старая запись остаётся как есть).
     */
    public function rehome(EmailMessage $row, string $folderPath, ?int $uid, ?int $mailboxFolderId): bool
    {
        try {
            $row->forceFill([
                'folder' => $folderPath,
                'imap_uid' => $uid,
                'mailbox_folder_id' => $mailboxFolderId,
            ])->saveQuietly();

            return true;
        } catch (\Illuminate\Database\QueryException $e) {
            Log::warning('ImapFolderSyncService: rehome conflict', [
                'email_message_id' => $row->id, 'folder' => $folderPath, 'error' => substr($e->getMessage(), 0, 200),
            ]);
            EmailMessage::query()->whereKey($row->id)->update(['mailbox_folder_id' => $mailboxFolderId]);

            return false;
        }
    }

    /* ============================ PUSH ============================ */

    /** Серверный путь для локальной папки: путь родителя + разделитель + сегмент имени. */
    public function pathFor(MailboxFolder $folder, ?string $delimiter = null): ?string
    {
        $delimiter ??= $this->delimiter();
        $segment = MailboxFolder::imapSegmentFromName($folder->name, $delimiter);
        if ($segment === '') {
            return null;
        }
        if ($folder->parent_id) {
            $parent = $folder->parent;
            if (! $parent || $parent->imap_path === null) {
                return null; // родитель ещё не на сервере — путь неизвестен
            }

            return $parent->imap_path . $delimiter . $segment;
        }

        return $segment;
    }

    /** CREATE + SUBSCRIBE (в job'е). */
    public function createOnServer(Mailbox $mailbox, MailboxFolder $folder): void
    {
        $path = $folder->imap_path ?? $this->pathFor($folder);
        if ($path === null) {
            throw new \RuntimeException("No IMAP path for folder {$folder->id}");
        }
        $client = $this->connector->imapClient($mailbox);
        try {
            $this->router->ensureFolder($client, $path, $this->delimiter());
        } finally {
            $client->disconnect();
        }
        $folder->forceFill(['imap_path' => $path, 'imap_synced_at' => now()])->save();
    }

    /** RENAME old → new (в job'е). Сервер переименовывает потомков сам. */
    public function renameOnServer(Mailbox $mailbox, string $oldPath, string $newPath): void
    {
        $client = $this->connector->imapClient($mailbox);
        try {
            $client->openFolder('INBOX', force_select: true);
            $r = $client->getConnection()->renameFolder($oldPath, $newPath);
            if (! $r->boolean()) {
                throw new \RuntimeException('RENAME failed: ' . implode(' ', array_map('strval', (array) $r->validatedData())));
            }
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Удалить папку на сервере (в job'е): письма → INBOX, подпапки → RENAME на
     * уровень выше, затем DELETE. Иначе Yandex отправит содержимое в «Корзину».
     *
     * @param  list<array{0:string,1:string}>  $childRenames  [oldPath, newPath]
     * @param  list<int>  $uids  UID писем в удаляемой папке
     */
    public function deleteOnServer(Mailbox $mailbox, string $path, array $childRenames, array $uids): void
    {
        $client = $this->connector->imapClient($mailbox);
        try {
            $conn = $client->getConnection();
            if ($uids !== []) {
                $this->moveUids($client, $mailbox, $path, $uids, 'INBOX', null);
            }
            foreach ($childRenames as [$old, $new]) {
                $client->openFolder('INBOX', force_select: true);
                $conn->renameFolder($old, $new);
            }
            $client->openFolder('INBOX', force_select: true);
            $r = $conn->deleteFolder($path);
            if (! $r->boolean()) {
                throw new \RuntimeException('DELETE failed: ' . implode(' ', array_map('strval', (array) $r->validatedData())));
            }
        } finally {
            $client->disconnect();
        }
    }

    /** UID MOVE (в job'е) с обновлением folder/imap_uid в БД по COPYUID. @param list<int> $uids */
    public function moveOnServer(Mailbox $mailbox, string $fromPath, array $uids, string $toPath, ?int $mailboxFolderId): void
    {
        $client = $this->connector->imapClient($mailbox);
        try {
            $this->moveUids($client, $mailbox, $fromPath, $uids, $toPath, $mailboxFolderId);
        } finally {
            $client->disconnect();
        }
    }

    /** @param list<int> $uids */
    private function moveUids(Client $client, Mailbox $mailbox, string $fromPath, array $uids, string $toPath, ?int $mailboxFolderId): void
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if ($uids === [] || $fromPath === $toPath) {
            return;
        }
        $conn = $client->getConnection();
        $client->openFolder($fromPath, force_select: true);
        sort($uids);
        foreach (array_chunk($uids, 200) as $chunk) {
            $resp = $conn->moveManyMessages($chunk, $toPath, IMAP::ST_UID);
            if (! $resp->boolean()) {
                throw new \RuntimeException('UID MOVE failed: ' . implode(' ', array_map('strval', (array) $resp->validatedData())));
            }
            $map = self::parseCopyUidMap((array) $resp->validatedData());
            foreach ($chunk as $uid) {
                $newUid = $map[$uid] ?? null;
                EmailMessage::query()
                    ->where('mailbox_id', $mailbox->id)
                    ->where('folder', $fromPath)
                    ->where('imap_uid', $uid)
                    ->update([
                        'folder' => $toPath,
                        'imap_uid' => $newUid, // null → pull подберёт по Message-ID
                        'mailbox_folder_id' => $mailboxFolderId,
                    ]);
            }
        }
    }

    /**
     * COPYUID (RFC 4315) → [старый uid => новый uid]. Наборы вида `1,5:7` ↔ `10:13`.
     *
     * @param  array<int, mixed>  $lines
     * @return array<int,int>
     */
    public static function parseCopyUidMap(array $lines): array
    {
        foreach ($lines as $line) {
            if (! is_string($line) || ! preg_match('/COPYUID\s+\d+\s+([\d:,]+)\s+([\d:,]+)/i', $line, $m)) {
                continue;
            }
            $src = self::expandUidSet($m[1]);
            $dst = self::expandUidSet($m[2]);
            if (count($src) !== count($dst)) {
                return [];
            }

            return array_combine($src, $dst) ?: [];
        }

        return [];
    }

    /** @return list<int> */
    public static function expandUidSet(string $set): array
    {
        $out = [];
        foreach (explode(',', $set) as $part) {
            if (str_contains($part, ':')) {
                [$a, $b] = array_map('intval', explode(':', $part, 2));
                for ($i = min($a, $b); $i <= max($a, $b); $i++) {
                    $out[] = $i;
                }
            } elseif ($part !== '') {
                $out[] = (int) $part;
            }
        }

        return $out;
    }

    /** @param array<string, array{delimiter?:string}> $list */
    private function delimiterFromList(array $list): ?string
    {
        foreach ($list as $info) {
            $d = (string) ($info['delimiter'] ?? '');
            if ($d !== '' && $d !== 'NIL') {
                return $d;
            }
        }

        return null;
    }
}
