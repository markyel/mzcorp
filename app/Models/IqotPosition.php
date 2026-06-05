<?php

namespace App\Models;

use App\Enums\IqotPositionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Позиция каталога в пуле IQOT-анализа + кэш результата по этой позиции.
 * Одна строка на catalog_item. См. миграцию create_iqot_positions_table.
 */
class IqotPosition extends Model
{
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'catalog_item_id',
        'iqot_submission_id',
        'requested_by_user_id',
        'status',
        'source',
        'lost_quote_count',
        'manual_requested_at',
        'qty',
        'unit',
        'client_ref',
        'payload_name',
        'payload_oem',
        'payload_brand',
        'report',
        'report_min_price',
        'report_offers_count',
        'iqot_item_status',
        'analyzed_at',
        'last_enqueued_at',
        'excluded_at',
        'excluded_by_user_id',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'lost_quote_count' => 'integer',
        'qty' => 'decimal:3',
        'report_offers_count' => 'integer',
        'report_min_price' => 'decimal:2',
        'report' => 'array',
        'manual_requested_at' => 'datetime',
        'analyzed_at' => 'datetime',
        'last_enqueued_at' => 'datetime',
        'excluded_at' => 'datetime',
    ];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(IqotSubmission::class, 'iqot_submission_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function excludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by_user_id');
    }

    public function isExcluded(): bool
    {
        return $this->excluded_at !== null;
    }

    public function statusEnum(): ?IqotPositionStatus
    {
        return IqotPositionStatus::tryFrom((string) $this->status);
    }

    public function hasReport(): bool
    {
        return $this->analyzed_at !== null && is_array($this->report) && ! empty($this->report);
    }

    /**
     * Свежий отчёт = есть отчёт и analyzed_at в окне актуальности
     * (iqot.report_fresh_days). По умолчанию 90 дней.
     */
    public function hasFreshReport(?int $freshDays = null): bool
    {
        if (! $this->hasReport()) {
            return false;
        }
        $freshDays ??= (int) app_setting('iqot.report_fresh_days', config('services.iqot.report_fresh_days', 90));

        return $this->analyzed_at->gte(now()->subDays(max(1, $freshDays)));
    }

    /**
     * Позиции со свежим отчётом (для подсветки/дедупа). Окно — в днях.
     */
    public function scopeWithFreshReport(Builder $q, int $freshDays): Builder
    {
        return $q->whereNotNull('analyzed_at')
            ->where('analyzed_at', '>=', now()->subDays(max(1, $freshDays)));
    }
}
