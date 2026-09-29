<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Проверка исходящего письма клиенту на соответствие образу компании.
 * См. App\Services\Mail\OutboundToneAuditService.
 *
 * @property list<string>|null $categories
 */
class OutboundToneReview extends Model
{
    public const CATEGORIES = [
        'rude' => 'Грубость, пренебрежение',
        'familiar' => 'Панибратство, жаргон',
        'blame' => 'Перекладывание ответственности',
        'warranty' => 'Гарантия и документы вразрез с политикой',
        'incompetent' => 'Некомпетентность, не ответил на вопрос',
        'refusal' => 'Отказ без альтернативы',
        'overpromise' => 'Обещания вне политики',
        'sloppy' => 'Неряшливость, телеграфный стиль',
    ];

    protected $fillable = [
        'email_message_id', 'request_id', 'user_id', 'sent_at', 'verdict', 'severity',
        'categories', 'quote', 'comment', 'suggestion', 'model', 'egregious', 'egregious_reason', 'better_reply',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'severity' => 'integer',
        'categories' => 'array',
        'egregious' => 'boolean',
    ];

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
