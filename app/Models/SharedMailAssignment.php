<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Состояние письма выбывшего менеджера в разделе «Почта выбышних»: назначенный
 * ответственный + флаг прочитанности. Сам список писем — живой запрос по
 * email_messages (mailbox недоступного менеджера, related_request_id IS NULL).
 * См. App\Services\Mail\SharedMailService.
 *
 * @property int $email_message_id
 * @property ?int $assigned_user_id
 * @property ?int $assigned_by_user_id
 * @property ?\Illuminate\Support\Carbon $assigned_at
 * @property ?\Illuminate\Support\Carbon $read_at
 * @property ?int $read_by_user_id
 */
class SharedMailAssignment extends Model
{
    protected $fillable = [
        'email_message_id',
        'assigned_user_id',
        'assigned_by_user_id',
        'assigned_at',
        'read_at',
        'read_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
