<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Автоматический мониторинг цены каталожной позиции (Фаза 4.3).
 * См. миграцию create_price_monitors_table и PriceMonitorService.
 *
 * @property int $catalog_item_id
 * @property int $interval_days
 * @property array<int, int>|null $supplier_ids
 * @property bool $is_active
 */
class PriceMonitor extends Model
{
    public const DEFAULT_INTERVAL_DAYS = 90;

    /** Разумные границы периода: чаще недели поставщика раздражает, дольше года бессмысленно. */
    public const MIN_INTERVAL_DAYS = 7;

    public const MAX_INTERVAL_DAYS = 365;

    protected $fillable = [
        'catalog_item_id',
        'interval_days',
        'supplier_ids',
        'last_dispatched_at',
        'next_due_at',
        'dispatch_count',
        'is_active',
        'disabled_at',
        'created_by_user_id',
        'last_inquiry_id',
    ];

    protected function casts(): array
    {
        return [
            'supplier_ids' => 'array',
            'last_dispatched_at' => 'datetime',
            'next_due_at' => 'datetime',
            'disabled_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function lastInquiry(): BelongsTo
    {
        return $this->belongsTo(SupplierInquiry::class, 'last_inquiry_id');
    }

    /** Активные, у которых подошёл срок перезапроса. */
    public function scopeDue(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->whereNotNull('next_due_at')
            ->where('next_due_at', '<=', now());
    }

    /** Сколько дней осталось до следующего запроса; отрицательное — просрочен. */
    public function daysLeft(): ?int
    {
        return $this->next_due_at === null ? null : (int) now()->startOfDay()->diffInDays($this->next_due_at->startOfDay(), false);
    }

    /** Период в допустимых границах. */
    public static function clampInterval(int $days): int
    {
        return max(self::MIN_INTERVAL_DAYS, min(self::MAX_INTERVAL_DAYS, $days));
    }
}
