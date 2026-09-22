<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Решение авто-КП, зафиксированное на момент прихода заявки.
 * См. миграцию 2026_09_22_100000_create_auto_quote_snapshots_table.
 */
class AutoQuoteSnapshot extends Model
{
    protected $fillable = [
        'request_id', 'evaluated_at', 'eligible', 'stopped_at', 'rule_version',
        'organization_id', 'pricing', 'total', 'lines', 'checks',
    ];

    protected $casts = [
        'evaluated_at' => 'datetime',
        'eligible' => 'boolean',
        'total' => 'decimal:2',
        'lines' => 'array',
        'checks' => 'array',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Снимок сделан прежней версией правила — сравнивать с ним честно, но с оговоркой. */
    public function isStaleRule(string $currentVersion): bool
    {
        return $this->rule_version !== $currentVersion;
    }

    /**
     * Вердикт в том же виде, в каком его отдаёт правило, — чтобы страница
     * работала одинаково и со снимком, и со свежим расчётом.
     *
     * @return array<string, mixed>
     */
    public function toVerdict(): array
    {
        return [
            'eligible' => (bool) $this->eligible,
            'stopped_at' => $this->stopped_at,
            'checks' => (array) ($this->checks ?? []),
            'lines' => (array) ($this->lines ?? []),
            'total' => (float) $this->total,
            'organization' => $this->organization,
            'pricing' => (string) $this->pricing,
            'snapshot_at' => $this->evaluated_at,
        ];
    }
}
