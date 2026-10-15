<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Подписка пула QuoteParts: группа товаров + фильтры. Подходящие позиции держатся в пуле
 * сами; позиции, переставшие подходить ко всем правилам, предлагаются к удалению.
 *
 * filters: purchased_years (закупали в 1С за N лет), requested_days (спрашивали клиенты за
 * N дней), in_stock, price_not_actual, brands (список).
 */
class QuotePartsPoolRule extends Model
{
    protected $table = 'quoteparts_pool_rules';

    protected $fillable = ['name', 'group_code', 'filters', 'is_active', 'matched_count', 'applied_at', 'created_by_user_id'];

    protected $casts = [
        'filters' => 'array',
        'is_active' => 'boolean',
        'applied_at' => 'datetime',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Коротко, по-человечески: «Китай · закупали за 3 года · есть на складе». */
    public function describe(): string
    {
        $f = (array) ($this->filters ?? []);
        $parts = [$this->group_code ? (SupplyGroup::label($this->group_code) ?? $this->group_code) : 'все группы'];
        if (! empty($f['purchased_years'])) {
            $parts[] = 'закупали за '.(int) $f['purchased_years'].' г.';
        }
        if (! empty($f['requested_days'])) {
            $parts[] = 'спрашивали за '.(int) $f['requested_days'].' дн.';
        }
        if (! empty($f['in_stock'])) {
            $parts[] = 'есть на складе';
        }
        if (! empty($f['price_not_actual'])) {
            $parts[] = 'цена не актуальна';
        }
        if (! empty($f['brands'])) {
            $parts[] = 'бренды: '.implode(', ', (array) $f['brands']);
        }

        return implode(' · ', $parts);
    }
}
