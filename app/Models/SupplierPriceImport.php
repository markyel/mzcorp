<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Загрузка выгрузки 1С «Номенклатура (история цен)» через интерфейс
 * (см. ImportSupplierPricesJob, CatalogSupplierPriceImportService).
 */
class SupplierPriceImport extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_PARSING = 'parsing';

    public const STATUS_PARSED = 'parsed';

    public const STATUS_APPLYING = 'applying';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    public const LABELS = [
        self::STATUS_UPLOADED => 'Загружен',
        self::STATUS_PARSING => 'Разбирается',
        self::STATUS_PARSED => 'Ждёт подтверждения',
        self::STATUS_APPLYING => 'Записывается',
        self::STATUS_APPLIED => 'Записан',
        self::STATUS_FAILED => 'Ошибка',
    ];

    protected $fillable = [
        'user_id',
        'file_path',
        'original_name',
        'status',
        'stats',
        'unmatched_suppliers',
        'error',
        'written',
        'applied_at',
    ];

    protected $casts = [
        'stats' => 'array',
        'unmatched_suppliers' => 'array',
        'applied_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [self::STATUS_UPLOADED, self::STATUS_PARSING, self::STATUS_APPLYING], true);
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }
}
