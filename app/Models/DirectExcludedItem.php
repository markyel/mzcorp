<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Позиция, исключённая из рекламы в Директе вручную (раздел «Директ»).
 * См. миграцию 2026_09_21_120000_create_direct_excluded_items_table.
 */
class DirectExcludedItem extends Model
{
    protected $fillable = ['catalog_item_id', 'sku', 'reason', 'excluded_by_user_id'];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function excludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by_user_id');
    }
}
