<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $request_id
 * @property string $internal_code
 * @property int $version
 * @property QuotationStatus $status
 * @property ?string $recipient_name
 * @property ?string $recipient_inn
 * @property ?string $recipient_address
 * @property ?string $recipient_card_text
 * @property ?int $responsible_user_id
 * @property int $valid_days
 * @property float $discount_percent
 * @property float $subtotal
 * @property float $discount_amount
 * @property float $total
 * @property float $vat_rate
 * @property float $vat_amount
 * @property ?int $sent_email_message_id
 * @property ?\Illuminate\Support\Carbon $sent_at
 * @property ?\Illuminate\Support\Carbon $accepted_at
 * @property ?\Illuminate\Support\Carbon $declined_at
 * @property ?\Illuminate\Support\Carbon $cancelled_at
 * @property ?array $snapshot_company
 * @property ?string $notes
 * @property ?int $created_by_user_id
 * @property-read \Illuminate\Database\Eloquent\Collection<int, QuotationItem> $items
 */
class Quotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'internal_code',
        'version',
        'status',
        'recipient_name',
        'recipient_inn',
        'recipient_address',
        'recipient_card_text',
        'responsible_user_id',
        'valid_days',
        'discount_percent',
        'subtotal',
        'discount_amount',
        'total',
        'vat_rate',
        'vat_amount',
        'sent_email_message_id',
        'sent_at',
        'accepted_at',
        'declined_at',
        'cancelled_at',
        'snapshot_company',
        'notes',
        // Общий клиентский комментарий, печатается в PDF (≠ внутренний notes).
        'client_comment',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'version' => 'integer',
            'valid_days' => 'integer',
            'discount_percent' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'snapshot_company' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('position');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function sentEmailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'sent_email_message_id');
    }

    /** Дата «Гарантировано до …» в шапке PDF. */
    protected function validUntil(): Attribute
    {
        return Attribute::get(function () {
            $base = $this->sent_at ?? $this->created_at;
            return $base ? $base->copy()->addDays($this->valid_days) : null;
        });
    }
}
