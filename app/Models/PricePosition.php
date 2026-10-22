<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Позиция «Актуализации цен» в работе снабжения: добавлена в список выгрузки 1С, выгружена,
 * сбор цен по ней завершён — позиция убрана с экрана, пока не придёт новое предложение.
 *
 * @property array{offer_id:?int, supplier:?string, price:?float, currency:?string}|null $closed_offer
 */
class PricePosition extends Model
{
    protected $fillable = [
        'sku',
        'catalog_item_id',
        'in_export_at',
        'in_export_by_user_id',
        'exported_at',
        'price_export_id',
        'closed_at',
        'closed_by_user_id',
        'closed_offer',
    ];

    protected $casts = [
        'in_export_at' => 'datetime',
        'exported_at' => 'datetime',
        'closed_at' => 'datetime',
        'closed_offer' => 'array',
    ];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function priceExport(): BelongsTo
    {
        return $this->belongsTo(PriceExport::class);
    }

    public function inExport(): bool
    {
        return $this->in_export_at !== null;
    }
}
