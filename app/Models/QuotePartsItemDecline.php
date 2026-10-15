<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Копия отметки поставщика «не сможем предложить» из QuoteParts. В «Снабжении» позиция
 * помечается «не поставляет»; новые запросы через QuoteParts этому поставщику по ней не
 * уходят (QuoteParts сразу отвечает отказом).
 */
class QuotePartsItemDecline extends Model
{
    protected $table = 'quoteparts_item_declines';

    protected $fillable = [
        'remote_id',
        'catalog_item_id',
        'sku',
        'supplier_key',
        'supplier_name',
        'reason',
        'declined_at',
        'restored_at',
        'remote_updated_at',
    ];

    protected $casts = [
        'declined_at' => 'datetime',
        'restored_at' => 'datetime',
        'remote_updated_at' => 'datetime',
    ];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    /** @param Builder<self> $q */
    public function scopeInForce(Builder $q): void
    {
        $q->whereNull('restored_at');
    }
}
