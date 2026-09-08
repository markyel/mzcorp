<?php

namespace App\Services\Mail;

use App\Enums\MailboxType;
use App\Jobs\Mail\PushImapFolderOpJob;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\MailboxFolder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Пользовательские папки почтового клиента: дерево на ящик, перенос писем,
 * счётчики. Доступ — как к ящику (MailboxAccessService): папки общего ящика
 * видят и правят все, кто видит ящик.
 *
 * Личные ящики с владельцем синхронизируют папки и расположение писем с
 * IMAP-сервером (ImapFolderSyncService, PushImapFolderOpJob): локально меняем
 * сразу, на сервер — job'ом. Общие ящики — папки только в mzCorp.
 */
class MailboxFolderService
{
    public function __construct(
        private readonly MailboxAccessService $access,
        private readonly ImapFolderSyncService $imap,
    ) {
    }

    /**
     * Дерево папок ящика, сплющенное в порядке обхода, с глубиной и счётчиками:
     * total — писем в папке, unread — непрочитанных ТЕКУЩИМ пользователем.
     *
     * @return list<array{id:int, key:string, name:string, depth:int, parent_id:?int, total:int, unread:int}>
     */
    public function tree(Mailbox $mailbox, User $user): array
    {
        $folders = MailboxFolder::query()
            ->where('mailbox_id', $mailbox->id)
            ->orderBy('position')->orderBy('name')
            ->get(['id', 'parent_id', 'name']);
        if ($folders->isEmpty()) {
            return [];
        }

        // Непрочитанные: у личного ящика с владельцем — владельца (одна правда
        // на ящик, как в Client::readStateUserByMailbox), иначе — текущего.
        $stateUserId = ($mailbox->type === MailboxType::Personal && $mailbox->owner_user_id)
            ? (int) $mailbox->owner_user_id
            : (int) $user->id;

        $counts = DB::table('email_messages as e')
            ->leftJoin('email_message_user_states as s', function ($j) use ($stateUserId) {
                $j->on('s.email_message_id', '=', 'e.id')->where('s.user_id', '=', $stateUserId);
            })
            ->whereIn('e.mailbox_folder_id', $folders->pluck('id'))
            ->where('e.is_draft', false)
            ->groupBy('e.mailbox_folder_id')
            ->selectRaw("e.mailbox_folder_id as fid, count(*) as total, count(*) filter (where e.direction = 'inbound' and s.read_at is null) as unread")
            ->get()
            ->keyBy('fid');

        $byParent = [];
        foreach ($folders as $f) {
            $byParent[$f->parent_id ?? 0][] = $f;
        }

        $out = [];
        $walk = function (?int $parentId, int $depth) use (&$walk, &$out, $byParent, $counts) {
            foreach ($byParent[$parentId ?? 0] ?? [] as $f) {
                $c = $counts->get($f->id);
                $out[] = [
                    'id' => (int) $f->id,
                    'key' => 'f:' . $f->id,
                    'name' => (string) $f->name,
                    'depth' => $depth,
                    'parent_id' => $f->parent_id ? (int) $f->parent_id : null,
                    'total' => (int) ($c->total ?? 0),
                    'unread' => (int) ($c->unread ?? 0),
                ];
                if ($depth + 1 < MailboxFolder::MAX_DEPTH) {
                    $walk((int) $f->id, $depth + 1);
                }
            }
        };
        $walk(null, 0);

        return $out;
    }

    public function create(Mailbox $mailbox, string $name, ?int $parentId, User $user): MailboxFolder
    {
        $this->ensureAccess($mailbox, $user);
        $name = $this->cleanName($name);

        $parent = null;
        if ($parentId) {
            $parent = MailboxFolder::query()->where('mailbox_id', $mailbox->id)->findOrFail($parentId);
            if ($this->depthOf($parent) + 1 >= MailboxFolder::MAX_DEPTH) {
                throw new \DomainException('Максимум ' . MailboxFolder::MAX_DEPTH . ' уровня вложенности.');
            }
        }
        $exists = MailboxFolder::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('parent_id', $parent?->id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->exists();
        if ($exists) {
            throw new \DomainException('Папка с таким именем здесь уже есть.');
        }

        $position = (int) MailboxFolder::query()
            ->where('mailbox_id', $mailbox->id)->where('parent_id', $parent?->id)->max('position') + 1;

        $folder = MailboxFolder::create([
            'mailbox_id' => $mailbox->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'position' => $position,
            'created_by_user_id' => $user->id,
        ]);

        // Личный ящик с владельцем → папка и на сервере (путь ставим сразу,
        // job подтверждает CREATE; imap_synced_at проставит job либо pull).
        if ($this->imap->isServerSynced($mailbox)) {
            $path = $this->imap->pathFor($folder);
            if ($path !== null) {
                $folder->forceFill(['imap_path' => $path])->save();
                PushImapFolderOpJob::dispatch((int) $mailbox->id, 'create', ['folder_id' => (int) $folder->id]);
            }
        }

        return $folder;
    }

    public function rename(MailboxFolder $folder, string $name, User $user): void
    {
        $this->ensureAccess($folder->mailbox, $user);
        $name = $this->cleanName($name);

        if ($folder->imap_path === null || ! $this->imap->isServerSynced($folder->mailbox)) {
            $folder->update(['name' => $name]);

            return;
        }

        $delimiter = $this->imap->delimiter();
        $oldPath = $folder->imap_path;
        $newPath = $this->imap->pathFor($folder->replicate(['imap_path'])->forceFill(['name' => $name, 'parent_id' => $folder->parent_id]), $delimiter);
        if ($newPath === null || $newPath === $oldPath) {
            $folder->update(['name' => $name]);

            return;
        }

        DB::transaction(function () use ($folder, $name, $oldPath, $newPath, $delimiter) {
            $folder->update(['name' => $name, 'imap_path' => $newPath]);
            $this->rewritePaths((int) $folder->mailbox_id, $oldPath, $newPath, $delimiter);
        });
        PushImapFolderOpJob::dispatch((int) $folder->mailbox_id, 'rename', ['old_path' => $oldPath, 'new_path' => $newPath]);
    }

    /**
     * Удалить папку: письма — во «Входящие» (mailbox_folder_id = NULL),
     * подпапки — на уровень выше. На сервере (личный ящик) то же самое делает
     * job: письма UID MOVE → INBOX, подпапки RENAME, затем DELETE.
     */
    public function delete(MailboxFolder $folder, User $user): int
    {
        $this->ensureAccess($folder->mailbox, $user);
        $serverSync = $folder->imap_path !== null && $this->imap->isServerSynced($folder->mailbox);
        $delimiter = $this->imap->delimiter();

        $payload = null;
        if ($serverSync) {
            $uids = EmailMessage::query()
                ->where('mailbox_id', $folder->mailbox_id)
                ->where('folder', $folder->imap_path)
                ->whereNotNull('imap_uid')
                ->pluck('imap_uid')->map(fn ($u) => (int) $u)->values()->all();
            $childRenames = [];
            $parentPath = $folder->parent_id ? $folder->parent?->imap_path : null;
            foreach (MailboxFolder::query()->where('parent_id', $folder->id)->whereNotNull('imap_path')->get() as $child) {
                $segment = MailboxFolder::imapSegmentFromName($child->name, $delimiter);
                $childRenames[] = [$child->imap_path, $parentPath !== null ? $parentPath . $delimiter . $segment : $segment];
            }
            $payload = ['path' => $folder->imap_path, 'child_renames' => $childRenames, 'uids' => $uids];
        }

        $moved = DB::transaction(function () use ($folder, $payload, $delimiter) {
            $moved = EmailMessage::query()->where('mailbox_folder_id', $folder->id)->update(['mailbox_folder_id' => null]);
            if ($payload !== null) {
                foreach ($payload['child_renames'] as [$old, $new]) {
                    MailboxFolder::query()->where('mailbox_id', $folder->mailbox_id)->where('imap_path', $old)->update(['imap_path' => $new]);
                    $this->rewritePaths((int) $folder->mailbox_id, $old, $new, $delimiter);
                }
            }
            MailboxFolder::query()->where('parent_id', $folder->id)->update(['parent_id' => $folder->parent_id]);
            $folder->delete();

            return $moved;
        });

        if ($payload !== null) {
            PushImapFolderOpJob::dispatch((int) $folder->mailbox_id, 'delete', $payload);
        }

        return $moved;
    }

    /**
     * Переименование ветки на сервере: обновить imap_path потомков и колонку
     * folder у писем (само переименованное звено обновляется вызывающим).
     */
    private function rewritePaths(int $mailboxId, string $oldPath, string $newPath, string $delimiter): void
    {
        $prefix = $oldPath . $delimiter;
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%';

        foreach (MailboxFolder::query()->where('mailbox_id', $mailboxId)->where('imap_path', 'like', $like)->get() as $desc) {
            $desc->forceFill(['imap_path' => $newPath . $delimiter . substr($desc->imap_path, strlen($prefix))])->save();
        }
        EmailMessage::query()->where('mailbox_id', $mailboxId)->where('folder', $oldPath)->update(['folder' => $newPath]);
        EmailMessage::query()->where('mailbox_id', $mailboxId)->where('folder', 'like', $like)
            ->update(['folder' => DB::raw("'" . str_replace("'", "''", $newPath . $delimiter) . "' || substr(folder, " . (strlen($prefix) + 1) . ')')]);
    }

    /**
     * Перенести письма в папку (null — обратно во входящие). Только письма
     * доступных пользователю ящиков; папка должна принадлежать ящику письма.
     *
     * @param  list<int>  $messageIds
     * @return int  сколько перенесено
     */
    public function moveMessages(array $messageIds, ?int $folderId, User $user): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
        if ($ids === []) {
            return 0;
        }
        $mailboxIds = $this->access->mailboxIdsFor($user);
        $folder = $folderId ? MailboxFolder::query()->findOrFail($folderId) : null;
        if ($folder && ! in_array((int) $folder->mailbox_id, $mailboxIds, true)) {
            throw new \DomainException('Нет доступа к ящику этой папки.');
        }

        $q = EmailMessage::query()
            ->whereKey($ids)
            ->whereIn('mailbox_id', $mailboxIds)
            ->where('is_draft', false);
        if ($folder) {
            // Папка принадлежит одному ящику — письма других ящиков в неё не кладём.
            $q->where('mailbox_id', $folder->mailbox_id);
        }

        $rows = (clone $q)->get(['id', 'mailbox_id', 'folder', 'imap_uid', 'mailbox_folder_id']);
        $n = $q->update(['mailbox_folder_id' => $folder?->id]);

        // На сервер (личные ящики): UID MOVE по группам (ящик, исходная папка).
        $toPath = $folder?->imap_path ?? 'INBOX';
        foreach ($rows->groupBy('mailbox_id') as $mailboxId => $group) {
            $mailbox = Mailbox::query()->find($mailboxId);
            if (! $mailbox || ! $this->imap->isServerSynced($mailbox)) {
                continue;
            }
            if ($folder && $folder->imap_path === null) {
                continue; // папка ещё не на сервере — pull/CREATE догонит позже
            }
            foreach ($group->whereNotNull('imap_uid')->groupBy('folder') as $fromPath => $msgs) {
                if ((string) $fromPath === $toPath || (string) $fromPath === '') {
                    continue;
                }
                PushImapFolderOpJob::dispatch((int) $mailboxId, 'move', [
                    'from_path' => (string) $fromPath,
                    'uids' => $msgs->pluck('imap_uid')->map(fn ($u) => (int) $u)->values()->all(),
                    'to_path' => $toPath,
                    'mailbox_folder_id' => $folder?->id,
                ]);
            }
        }

        return $n;
    }

    private function depthOf(MailboxFolder $folder): int
    {
        $depth = 0;
        $cur = $folder;
        while ($cur->parent_id && $depth < 10) {
            $cur = $cur->parent;
            $depth++;
        }

        return $depth;
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            throw new \DomainException('Укажите имя папки.');
        }

        return mb_substr($name, 0, MailboxFolder::NAME_MAX);
    }

    private function ensureAccess(Mailbox $mailbox, User $user): void
    {
        if (! $this->access->canAccessMailbox($user, (int) $mailbox->id)) {
            throw new \DomainException('Ящик недоступен.');
        }
    }
}
