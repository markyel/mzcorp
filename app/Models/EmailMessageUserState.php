<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Персональное состояние письма в разделе «Почта»: прочитано / помечено.
 *
 * Не трогает IMAP-флаги (\Seen НЕ ставим — CLAUDE.md §8). Отсутствие записи =
 * непрочитано и без флага. См. App\Services\Mail\MailReadService.
 */
class EmailMessageUserState extends Model
{
    protected $table = 'email_message_user_states';

    protected $fillable = [
        'email_message_id',
        'user_id',
        'read_at',
        'flagged_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'flagged_at' => 'datetime',
        ];
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
