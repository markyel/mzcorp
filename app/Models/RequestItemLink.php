<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Связь child-item ↔ parent-item между наследующей и архивной заявками
 * (Phase 2.1 inheritance).
 *
 * Source: LazyLift @ 2026_04_21_100000 (drop-in).
 *
 * Гарантия: у одного `child_item_id` может быть только одна активная
 * связь (unique partial index `is_active = true`). История неактивных
 * связей сохраняется для аудита.
 */
class RequestItemLink extends Model
{
    protected $fillable = [
        'child_item_id',
        'parent_item_id',
        'qty_ratio',
        'mapping_source',
        'mapping_confidence',
        'is_active',
        'linked_by',
    ];

    protected $casts = [
        'qty_ratio' => 'decimal:2',
        'mapping_confidence' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function childItem(): BelongsTo
    {
        return $this->belongsTo(RequestItem::class, 'child_item_id');
    }

    public function parentItem(): BelongsTo
    {
        return $this->belongsTo(RequestItem::class, 'parent_item_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
