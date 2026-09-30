<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Группа поставщиков («Китай», «Европа», «Поручни», «Канаты»…): выбор
 * поставщиков для запроса цены целой группой.
 */
class SupplierGroup extends Model
{
    protected $fillable = ['name', 'sort_order', 'created_by_user_id'];

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'supplier_group_members')->withTimestamps();
    }
}
