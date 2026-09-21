<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись журнала операций с API Директа.
 * См. миграцию 2026_09_21_190000_create_direct_operations_table.
 */
class DirectOperation extends Model
{
    protected $fillable = [
        'service', 'method', 'sku', 'ok', 'request', 'response',
        'units_spent', 'units_rest', 'error_code', 'error_message', 'user_id',
    ];

    protected $casts = [
        'ok' => 'boolean',
        'request' => 'array',
        'response' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Коротко для журнала: «adgroups.add M00193 — ок». */
    public function title(): string
    {
        return $this->service.'.'.$this->method.($this->sku ? ' '.$this->sku : '');
    }
}
