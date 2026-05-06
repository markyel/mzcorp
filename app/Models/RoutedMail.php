<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit-запись срабатывания правила маршрутизации (Foundation §«Новые модели»).
 *
 * Одно письмо может иметь несколько RoutedMail, если оно прошло несколько
 * non-terminal правил.
 */
class RoutedMail extends Model
{
    protected $fillable = [
        'email_message_id',
        'rule_id',
        'ai_classified_as',
        'action_taken',
        'forwarded_to',
        'label_applied',
        'success',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'bool',
            'processed_at' => 'datetime',
        ];
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(MailRoutingRule::class, 'rule_id');
    }
}
