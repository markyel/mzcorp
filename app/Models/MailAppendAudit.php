<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Аудит каждой попытки IMAP APPEND через MailDeliverToManagerService.
 *
 * См. миграцию 2026_05_27_180000_create_mail_append_audit_table.php — это
 * диагностический trace для расследования источника cross-mailbox дублей.
 *
 * Lifecycle:
 *   1) В начале deliver() — создаётся row со status='pending'.
 *   2) При skip (guard'ы) — status='skipped', skip_reason=...
 *   3) При успешном appendMessage + сохранении inbox_deliveries[] —
 *      status='success'.
 *   4) При исключении до записи artifact'а — status='failed',
 *      error_message=... Это самый интересный кейс: APPEND ушёл, но artifact
 *      не записался → потенциально создаст «yandex_side fake».
 */
class MailAppendAudit extends Model
{
    protected $table = 'mail_append_audit';

    protected $fillable = [
        'email_message_id',
        'target_user_id',
        'target_mailbox_id',
        'origin_mailbox_id',
        'message_id_rfc',
        'subject',
        'status',
        'skip_reason',
        'error_message',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function targetMailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'target_mailbox_id');
    }

    public function originMailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'origin_mailbox_id');
    }
}
