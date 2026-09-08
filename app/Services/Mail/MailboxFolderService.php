<?php

namespace App\Services\Mail;

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
 * Папки живут в mzCorp; на IMAP-сервере письмо остаётся на месте (Yandex не
 * делает EXPUNGE после UID MOVE — физический перенос оставил бы дубль в
 * Yandex-интерфейсе, см. MailFolderRouter).
 */
class MailboxFolderService
{
    public function __construct(private readonly MailboxAccessService $access)
    {
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

        $counts = DB::table('email_messages as e')
            ->leftJoin('email_message_user_states as s', function ($j) use ($user) {
                $j->on('s.email_message_id', '=', 'e.id')->where('s.user_id', '=', $user->id);
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

        return MailboxFolder::create([
            'mailbox_id' => $mailbox->id,
            'parent_id' => $parent?->id,
            'name' => $name,
            'position' => $position,
            'created_by_user_id' => $user->id,
        ]);
    }

    public function rename(MailboxFolder $folder, string $name, User $user): void
    {
        $this->ensureAccess($folder->mailbox, $user);
        $folder->update(['name' => $this->cleanName($name)]);
    }

    /**
     * Удалить папку: письма — во «Входящие» (mailbox_folder_id = NULL),
     * подпапки — на уровень выше.
     */
    public function delete(MailboxFolder $folder, User $user): int
    {
        $this->ensureAccess($folder->mailbox, $user);

        return DB::transaction(function () use ($folder) {
            $moved = EmailMessage::query()->where('mailbox_folder_id', $folder->id)->update(['mailbox_folder_id' => null]);
            MailboxFolder::query()->where('parent_id', $folder->id)->update(['parent_id' => $folder->parent_id]);
            $folder->delete();

            return $moved;
        });
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

        return $q->update(['mailbox_folder_id' => $folder?->id]);
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
