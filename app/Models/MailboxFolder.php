<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Пользовательская папка почтового клиента (с вложенностью), принадлежит ящику.
 * Письма — email_messages.mailbox_folder_id. См. MailboxFolderService.
 */
class MailboxFolder extends Model
{
    public const NAME_MAX = 80;

    public const MAX_DEPTH = 3;

    protected $fillable = [
        'mailbox_id',
        'parent_id',
        'name',
        // Путь на IMAP-сервере (raw, MUTF-7) — только у папок личных ящиков,
        // синхронизируемых с сервером (ImapFolderSyncService); NULL — папка
        // живёт только в mzCorp.
        'imap_path',
        'imap_synced_at',
        'position',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['imap_synced_at' => 'datetime'];
    }

    /** Имя папки для показа: декодированное из MUTF-7 последнее звено пути. */
    public static function displayNameFromImapPath(string $imapPath, string $delimiter): string
    {
        $parts = $delimiter !== '' ? explode($delimiter, $imapPath) : [$imapPath];
        $last = (string) end($parts);
        $decoded = @mb_convert_encoding($last, 'UTF-8', 'UTF7-IMAP');

        return is_string($decoded) && $decoded !== '' ? $decoded : $last;
    }

    /** Звено пути на сервере из имени папки: MUTF-7, без символа-разделителя. */
    public static function imapSegmentFromName(string $name, string $delimiter): string
    {
        $clean = $delimiter !== '' ? str_replace($delimiter, ' ', $name) : $name;
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean);
        $encoded = @mb_convert_encoding($clean, 'UTF7-IMAP', 'UTF-8');

        return is_string($encoded) && $encoded !== '' ? $encoded : $clean;
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class, 'mailbox_folder_id');
    }

    /** Ключ папки в URL/состоянии клиента: `f:<id>`. */
    public function key(): string
    {
        return 'f:' . $this->id;
    }

    public static function idFromKey(?string $key): ?int
    {
        if ($key === null || ! str_starts_with($key, 'f:')) {
            return null;
        }
        $id = (int) substr($key, 2);

        return $id > 0 ? $id : null;
    }
}
