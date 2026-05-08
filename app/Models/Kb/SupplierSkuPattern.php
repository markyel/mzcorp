<?php

namespace App\Models\Kb;

use Illuminate\Database\Eloquent\Model;

/**
 * MyLift adaptation: relation supplier() удалена — модель `App\Models\Supplier`
 * ещё не существует. Будет добавлена когда появится supplier infra (Phase 2.5+).
 * Сейчас таблица `supplier_sku_patterns` пустая — `ArticleClassificationService`
 * получает empty collection в supplier-блоке и пропускает его.
 */
class SupplierSkuPattern extends Model
{
    protected $table = 'supplier_sku_patterns';

    protected $fillable = [
        'supplier_id',
        'pattern',
        'description',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
