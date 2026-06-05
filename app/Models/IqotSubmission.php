<?php

namespace App\Models;

use App\Enums\IqotSubmissionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Батч позиций, отправленный в IQOT на анализ цен. Async-poll-only.
 * Связь с позициями каталога — через iqot_positions.iqot_submission_id.
 * Порт из LazyLift.
 */
class IqotSubmission extends Model
{
    protected $fillable = [
        'created_by_user_id',
        'idempotency_key',
        'submission_id',
        'client_ref',
        'local_status',
        'iqot_status',
        'iqot_stage',
        'catalog_item_ids',
        'payload',
        'last_status_response',
        'report',
        'status_changed_at',
        'next_check_after',
        'last_polled_at',
        'report_fetched_at',
        'error_code',
        'error_message',
        'request_id_header',
    ];

    protected $casts = [
        'catalog_item_ids' => 'array',
        'payload' => 'array',
        'last_status_response' => 'array',
        'report' => 'array',
        'status_changed_at' => 'datetime',
        'next_check_after' => 'datetime',
        'last_polled_at' => 'datetime',
        'report_fetched_at' => 'datetime',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(IqotPosition::class);
    }

    public function statusEnum(): ?IqotSubmissionStatus
    {
        return IqotSubmissionStatus::tryFrom((string) $this->local_status);
    }

    public function isTerminal(): bool
    {
        return $this->statusEnum()?->isTerminal() ?? false;
    }

    public function canBeCancelled(): bool
    {
        return ! empty($this->submission_id) && ($this->statusEnum()?->canBeCancelled() ?? false);
    }

    public function hasReport(): bool
    {
        return is_array($this->report) && ! empty($this->report);
    }

    /**
     * Submissions, которые пора опросить: есть submission_id, статус не
     * терминальный, next_check_after отсутствует или прошёл.
     */
    public function scopeNeedsPolling(Builder $q): Builder
    {
        return $q->whereNotNull('submission_id')
            ->whereNotIn('local_status', [
                IqotSubmissionStatus::Completed->value,
                IqotSubmissionStatus::Cancelled->value,
                IqotSubmissionStatus::Failed->value,
            ])
            ->where(function (Builder $inner) {
                $inner->whereNull('next_check_after')
                    ->orWhere('next_check_after', '<=', now());
            });
    }
}
