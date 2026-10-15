<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * У кого закупаем позицию каталога — выбор снабжения среди ответивших поставщиков.
 * Остаётся при следующих обновлениях цен: меняют только руками.
 */
class CatalogItemSupplierChoice extends Model
{
    protected $fillable = [
        'catalog_item_id',
        'supplier_key',
        'supplier_name',
        'source',
        'offer_ref',
        'price',
        'currency',
        'chosen_by_user_id',
        'chosen_at',
    ];

    protected $casts = [
        'price' => 'decimal:4',
        'chosen_at' => 'datetime',
    ];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function chosenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chosen_by_user_id');
    }
}
