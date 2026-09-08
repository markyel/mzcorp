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
        'position',
        'created_by_user_id',
    ];

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
