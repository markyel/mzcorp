<?php

namespace App\Models;

use App\Enums\MailDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Письмо (входящее или исходящее).
 *
 * Уникальность: (mailbox_id, folder, message_id). Одно физическое письмо
 * может лежать в Inbox у одного ящика и в Sent у другого — это две записи.
 *
 * Поля ai_*, classified_at, related_request_id, detected_artifacts
 * заполняются на следующих фазах (1.6, 1.8, Phase 4).
 */
class EmailMessage extends Model
{
    protected $fillable = [
        'mailbox_id',
        'folder',
        'direction',
        'imap_uid',
        'message_id',
        'in_reply_to',
        'references_header',
        'subject',
        'from_email',
        'from_name',
        'to_recipients',
        'cc_recipients',
        'sent_at',
        'body_plain',
        'body_html',
        'raw_source',
        'headers',
        'imap_flags',
        'ai_classification',
        'ai_classification_confidence',
        'classified_at',
        'detected_artifacts',
        'related_request_id',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MailDirection::class,
            'imap_uid' => 'integer',
            'references_header' => 'array',
            'to_recipients' => 'array',
            'cc_recipients' => 'array',
            'headers' => 'array',
            'imap_flags' => 'array',
            'detected_artifacts' => 'array',
            'sent_at' => 'datetime',
            'classified_at' => 'datetime',
            'ai_classification_confidence' => 'float',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }
}
