<?php

namespace App\Models;

use App\Enums\DetectorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Snapshot исходящего КП/счёта (Foundation §7, см. миграцию
 * create_outbound_quotes_table).
 *
 * Заполняется `ParseOutboundQuoteJob` после OutboundDocumentDetector /
 * OutboundDocumentClassifier подтвердил тип документа.
 */
class OutboundQuote extends Model
{
    public const STATUS_PARSING = 'parsing';
    public const STATUS_PARSED = 'parsed';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_FAILED = 'failed';

    public const SOURCE_ATTACHMENT = 'attachment';
    public const SOURCE_BODY = 'body';

    protected $fillable = [
        'request_id',
        'email_message_id',
        'email_attachment_id',
        'source',
        'document_type',
        'document_number',
        'document_date',
        'currency',
        'subtotal',
        'vat_amount',
        'total_amount',
        'vat_rate',
        'prices_include_vat',
        'status',
        'parse_error',
        'ai_raw_response',
        'payload',
        'parsed_at',
        'matched_at',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DetectorType::class,
            'document_date' => 'date',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'prices_include_vat' => 'boolean',
            'ai_raw_response' => 'array',
            'payload' => 'array',
            'parsed_at' => 'datetime',
            'matched_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(EmailAttachment::class, 'email_attachment_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OutboundQuoteItem::class)->orderBy('position');
    }

    public function isParsed(): bool
    {
        return in_array($this->status, [self::STATUS_PARSED, self::STATUS_MATCHED], true);
    }

    public function matchedCount(): int
    {
        return $this->items->whereNotNull('matched_request_item_id')->count();
    }
}
