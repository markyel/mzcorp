<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $quotation_id
 * @property int $position
 * @property ?int $request_item_id
 * @property ?int $catalog_item_id
 * @property float $catalog_unit_price
 * @property ?float $catalog_price_min
 * @property ?int $catalog_lead_time_days
 * @property bool $catalog_in_stock
 * @property string $snapshot_name
 * @property ?string $snapshot_sku
 * @property ?string $snapshot_brand
 * @property ?string $snapshot_brand_article
 * @property ?string $snapshot_photo_url
 * @property float $qty
 * @property string $unit
 * @property ?float $discount_percent
 * @property float $final_unit_price
 * @property float $line_total
 * @property float $vat_amount
 * @property ?string $delivery_text
 * @property ?string $notes
 */
class QuotationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'position',
        'request_item_id',
        'catalog_item_id',
        'catalog_unit_price',
        'catalog_price_min',
        'catalog_lead_time_days',
        'catalog_in_stock',
        'snapshot_name',
        'snapshot_sku',
        'snapshot_brand',
        'snapshot_brand_article',
        'snapshot_photo_url',
        'qty',
        'unit',
        'discount_percent',
        'final_unit_price',
        'line_total',
        'vat_amount',
        'delivery_text',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'catalog_unit_price' => 'decimal:2',
            'catalog_price_min' => 'decimal:2',
            'catalog_lead_time_days' => 'integer',
            'catalog_in_stock' => 'boolean',
            'qty' => 'decimal:3',
            'discount_percent' => 'decimal:2',
            'final_unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'vat_amount' => 'decimal:2',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(RequestItem::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
