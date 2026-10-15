<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Выгрузка новых цен поставщиков в Excel (под загрузку в 1С).
 */
class PriceExport extends Model
{
    protected $fillable = [
        'from_at',
        'to_at',
        'rows_count',
        'suppliers_count',
        'skipped_count',
        'file_path',
        'created_by_user_id',
    ];

    protected $casts = [
        'from_at' => 'datetime',
        'to_at' => 'datetime',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
