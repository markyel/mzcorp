<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отдельный код маркировки (КИЗ) из разобранного PDF. Хранится построчно,
 * чтобы искать «в какую поставку ушёл этот код» и ловить повторную подачу.
 */
class HonestSignCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'honest_sign_batch_id', 'code', 'gtin', 'serial',
        'article', 'product_name', 'source_file', 'page',
    ];

    protected $casts = ['page' => 'integer'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(HonestSignBatch::class, 'honest_sign_batch_id');
    }
}
