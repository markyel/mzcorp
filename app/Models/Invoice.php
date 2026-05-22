<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Счёт (Invoice). Выставляется ВНЕ системы (1С), мы только трекаем:
 *   номер, даты, статус, оплату. Cron expires pending по сроку.
 *
 * См. App\Services\Invoices\InvoiceService.
 */
class Invoice extends Model
{
    protected $fillable = [
        'request_id',
        'invoice_number',
        'issued_at',
        'expires_at',
        'validity_days',
        'status',
        'paid_at',
        'paid_by_user_id',
        'cancelled_at',
        'cancellation_reason',
        'comment',
        'created_by_user_id',
        'email_message_id',
        'amount_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'validity_days' => 'integer',
            'amount_snapshot' => 'decimal:2',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    /** Истёк ли срок (для UI badge). */
    public function isOverdue(): bool
    {
        return $this->status === InvoiceStatus::Pending && $this->expires_at->isPast();
    }
}
