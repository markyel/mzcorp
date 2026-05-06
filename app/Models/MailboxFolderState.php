<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UID-state на (mailbox × folder).
 *
 * При изменении uid_validity на стороне IMAP-сервера — нужен full resync
 * этой папки (см. Foundation §1 «Идемпотентность и устойчивость»).
 */
class MailboxFolderState extends Model
{
    protected $fillable = [
        'mailbox_id',
        'folder',
        'uid_validity',
        'last_uid_seen',
        'last_synced_at',
        'sync_count',
    ];

    protected function casts(): array
    {
        return [
            'uid_validity' => 'integer',
            'last_uid_seen' => 'integer',
            'sync_count' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
