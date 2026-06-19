<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Позиция запроса расценки поставщику (Фаза 3.2). См. миграцию
 * create_supplier_inquiry_items_table.
 *
 * @property int $supplier_inquiry_id
 * @property ?int $request_item_id
 * @property ?string $item_name
 * @property string $status  pending|quoted|refused|cancelled
 */
class SupplierInquiryItem extends Model
{
    protected $fillable = [
        'supplier_inquiry_id',
        'request_item_id',
        // Позиция-центричный RFQ из «Снабжения» (Фаза 4B): привязка к каталожной
        // позиции (M-артикул) вместо request_item.
        'catalog_item_id',
        'item_name',
        'status',
    ];

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(SupplierInquiry::class, 'supplier_inquiry_id');
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(RequestItem::class, 'request_item_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    /** Предложения поставщика по этой позиции (Фаза 3.3). */
    public function offers(): HasMany
    {
        return $this->hasMany(SupplierOffer::class)->orderByDesc('id');
    }
}
