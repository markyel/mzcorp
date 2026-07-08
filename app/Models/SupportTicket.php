<?php

namespace App\Models;

use App\Enums\SupportTicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Тикет «связь с создателем».
 *
 * Initial body хранится прямо в support_tickets.body — это упрощает
 * листинг (не надо джоинить messages). Тред ответов — в
 * support_ticket_messages. Вложения могут висеть на самом тикете
 * (message_id = null) или на конкретном ответе.
 */
class SupportTicket extends Model
{
    protected $fillable = [
        'user_id',
        'subject',
        'body',
        'status',
        'context',
        'assigned_to_user_id',
        'first_response_at',
        'resolved_at',
        'closed_at',
        'flagged_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SupportTicketStatus::class,
            'context' => 'array',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'flagged_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id')
            ->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class, 'ticket_id');
    }

    /**
     * Вложения, висящие на самом тикете (без message_id) — initial.
     */
    public function initialAttachments(): HasMany
    {
        return $this->attachments()->whereNull('message_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SupportTicketStatus::Open->value,
            SupportTicketStatus::InProgress->value,
        ]);
    }
}
