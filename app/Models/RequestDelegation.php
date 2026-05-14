<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foundation Фаза 2 — временный доступ другого менеджера к заявке
 * на время отсутствия оригинального владельца.
 *
 * См. миграцию `create_request_delegations_table` и
 * `ManagerUnavailabilityService::delegateActiveRequests`.
 */
class RequestDelegation extends Model
{
    protected $fillable = [
        'request_id',
        'original_user_id',
        'acting_user_id',
        'started_at',
        'ended_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function originalUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'original_user_id');
    }

    public function actingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_user_id');
    }

    /** Активные (не закрытые) делегации. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
