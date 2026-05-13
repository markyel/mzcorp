<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit-запись перехода статуса заявки (Phase 1.10, Foundation §852).
 *
 * Создаётся:
 *  - RequestStateService::transitionTo (event='manual')
 *  - RequestPauseService::pauseUntil / resume (event='manual' / 'auto_resume_pause')
 *  - ParseRequestItemsJob после autoAssign (event='system_initial')
 *
 * Все полу-ручные переходы пишутся сюда; UI таба «Активность» merge'ит с
 * `request_assignments` по `created_at` для единого timeline.
 */
class RequestStateChange extends Model
{
    protected $fillable = [
        'request_id',
        'from_status',
        'to_status',
        'by_user_id',
        'event',
        'comment',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function byUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by_user_id');
    }

    /**
     * Helper: вернуть enum-объект from_status (null если нет).
     */
    public function fromStatusEnum(): ?RequestStatus
    {
        return $this->from_status ? RequestStatus::tryFrom($this->from_status) : null;
    }

    public function toStatusEnum(): ?RequestStatus
    {
        return RequestStatus::tryFrom($this->to_status);
    }
}
