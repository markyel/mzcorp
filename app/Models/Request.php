<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Заявка клиента (минимальная Phase 1 версия).
 *
 * Полная модель с KB-полями, state-machine и corp-extern_code будет
 * в Phase 2-4 (Foundation §«Что переиспользуется»).
 */
class Request extends Model
{
    protected $table = 'requests';

    protected $fillable = [
        'internal_code',
        'email_message_id',
        'assigned_user_id',
        'status',
        'client_email',
        'client_name',
        'subject',
        'assigned_at',
        // Phase 2: очередь LLM-предположений «это уточнение существующей
        // позиции, а не новая». См. миграцию
        // 2026_05_12_160000_add_pending_clarifications_to_requests_table.
        'pending_clarifications',
    ];

    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'assigned_at' => 'datetime',
            'pending_clarifications' => 'array',
        ];
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RequestAssignment::class);
    }

    /**
     * Последнее назначение — для Pool используется для определения
     * sticky-чипа (`reason='auto_sticky'`) без подгрузки всей коллекции.
     */
    public function latestAssignment(): HasOne
    {
        return $this->hasOne(RequestAssignment::class)->latestOfMany('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequestItem::class)->orderBy('position');
    }

    /**
     * KB-контекст заявки (Phase 2.0). Заполняется RequestContextAnalysisService
     * при первом ResolveKbJob — содержит equipment_units[], mentioned_sources[]
     * и raw LLM-ответ.
     */
    public function context(): HasOne
    {
        return $this->hasOne(\App\Models\Kb\RequestContext::class, 'request_id');
    }
}
