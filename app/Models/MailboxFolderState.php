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
        // Зеркало истории папки (MailHistoryMirrorService): идём от свежих к старым.
        'history_low_uid',
        'history_completed_at',
        'history_imported',
    ];

    protected function casts(): array
    {
        return [
            'uid_validity' => 'integer',
            'last_uid_seen' => 'integer',
            'sync_count' => 'integer',
            'last_synced_at' => 'datetime',
            'history_low_uid' => 'integer',
            'history_completed_at' => 'datetime',
            'history_imported' => 'integer',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
