<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Предложение поставщика по позиции (Фаза 3.3). См. миграцию
 * create_supplier_offers_table и App\Services\Supplier\SupplierOfferParser.
 *
 * @property int $supplier_inquiry_id
 * @property ?int $supplier_inquiry_item_id
 * @property ?int $email_message_id
 * @property string $outcome  quoted|refused
 * @property ?string $price
 * @property ?string $currency
 * @property ?string $valid_until_text
 * @property ?string $refusal_reason
 * @property ?string $raw_quote
 */
class SupplierOffer extends Model
{
    protected $fillable = [
        'supplier_inquiry_id',
        'supplier_inquiry_item_id',
        'email_message_id',
        'outcome',
        'price',
        'currency',
        'valid_until_text',
        'refusal_reason',
        'raw_quote',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(SupplierInquiry::class, 'supplier_inquiry_id');
    }

    public function inquiryItem(): BelongsTo
    {
        return $this->belongsTo(SupplierInquiryItem::class, 'supplier_inquiry_item_id');
    }
}
