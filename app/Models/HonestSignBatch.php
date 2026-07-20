<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Одна загрузка в разделе «Честный знак»: пачка PDF (± файл поставки),
 * кто разбирал и что получилось. Сами файлы не хранятся — только результат.
 */
class HonestSignBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'title', 'pdf_count', 'codes_count', 'rows_filled', 'warnings',
    ];

    protected $casts = [
        'warnings' => 'array',
        'pdf_count' => 'integer',
        'codes_count' => 'integer',
        'rows_filled' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function codes(): HasMany
    {
        return $this->hasMany(HonestSignCode::class);
    }
}
