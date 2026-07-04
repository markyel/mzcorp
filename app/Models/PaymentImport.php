<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Шапка импорта оплат из 1С (раздел «Счета» → «Загрузить оплаты»).
 * Строки — ImportedPayment.
 */
class PaymentImport extends Model
{
    protected $fillable = [
        'filename',
        'uploaded_by_user_id',
        'rows_total',
        'marked_paid',
        'marked_partial',
        'already_paid',
        'unknown_recorded',
        'skipped_old',
        'errors',
    ];

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ImportedPayment::class);
    }
}
