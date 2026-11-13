<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M-артикул новой позиции «Актуализации цен» (предварительная T-… в QuoteParts), указанный снабжением
 * до появления товара в каталоге. См. PriceDraftSkuService.
 */
class PriceDraftSku extends Model
{
    protected $fillable = ['draft_code', 'sku', 'catalog_item_id', 'set_by_user_id', 'linked_at'];

    protected $casts = ['linked_at' => 'datetime'];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    /**
     * M-артикулы непривязанных позиций: код T-… => M-артикул.
     *
     * @param  iterable<string>  $draftCodes
     * @return array<string, string>
     */
    public static function unlinkedMap(iterable $draftCodes): array
    {
        $codes = collect($draftCodes)->filter(fn ($c) => is_string($c) && $c !== '')->unique()->values()->all();

        return $codes === [] ? [] : static::query()->whereIn('draft_code', $codes)->whereNull('catalog_item_id')
            ->pluck('sku', 'draft_code')->all();
    }
}
