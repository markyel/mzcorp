<?php

namespace App\Models;

use App\Enums\AttentionReason;
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
        // Phase 1.10 — state-machine: pause + terminal-close.
        'paused_until',
        'paused_from_status',
        'paused_reason',
        'closed_at',
        'closed_lost_reason',
        'closed_lost_comment',
        // Foundation §7.4: точная цитата из inbound-письма + ссылка
        // на это письмо. Заполняется когда InboundIntentClassifier
        // распознал decline.
        'closed_lost_quote',
        'closed_lost_source_message_id',
        // Foundation §5.2 — reanimate closed_lost.
        'reanimated_at',
        'reanimated_count',
        // Phase 1.11 — Attention-механизм (Foundation §5.3 + §5.5).
        'attention_required_at',
        'attention_reason',
        'attention_level',
        'attention_manual_by_user_id',
        // Pool re-sort: denormalized timestamp последней активности.
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'assigned_at' => 'datetime',
            'pending_clarifications' => 'array',
            'paused_until' => 'datetime',
            'closed_at' => 'datetime',
            'reanimated_at' => 'datetime',
            'reanimated_count' => 'integer',
            'attention_required_at' => 'datetime',
            'attention_reason' => AttentionReason::class,
            'attention_level' => 'integer',
            'attention_manual_by_user_id' => 'integer',
            'last_activity_at' => 'datetime',
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

    /**
     * Audit-лог переходов статусов (Phase 1.10).
     * Используется в табе «Активность» merge'ом с request_assignments.
     */
    public function stateChanges(): HasMany
    {
        return $this->hasMany(RequestStateChange::class);
    }

    /**
     * AI-решения DocumentDetector (Foundation §7).
     * Pending suggestions (status=suggested) — UI prompt'ы оператору.
     */
    public function aiDecisions(): HasMany
    {
        return $this->hasMany(AiDecision::class);
    }

    /**
     * Письмо клиента, из которого AI извлёк отказ (Foundation §7.4).
     */
    public function closedLostSourceMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'closed_lost_source_message_id');
    }

    /**
     * Foundation Фаза 2 — delegation механизм.
     * История всех делегаций (включая закрытые).
     */
    public function delegations(): HasMany
    {
        return $this->hasMany(RequestDelegation::class);
    }

    /**
     * Уточняющие вопросы клиенту (Foundation §6.2).
     * Каждый batch — одно исходящее письмо.
     */
    public function clarificationBatches(): HasMany
    {
        return $this->hasMany(ClarificationBatch::class);
    }

    /**
     * Активные делегации (ended_at IS NULL) — обычно 0 или 1 одновременно,
     * но schema допускает несколько (теоретически несколько оригиналов
     * для одной заявки никогда не будет, но запас).
     */
    public function activeDelegations(): HasMany
    {
        return $this->hasMany(RequestDelegation::class)->whereNull('ended_at');
    }

    /**
     * Помощник: текущий «исполняющий обязанности» (acting user) —
     * первый active delegation. Null если делегации нет.
     */
    public function actingUser(): ?User
    {
        $delegation = $this->relationLoaded('activeDelegations')
            ? $this->activeDelegations->first()
            : $this->activeDelegations()->with('actingUser')->first();

        return $delegation?->actingUser;
    }

    /**
     * Помощник: ИСТИННЫЙ «владелец» (по полю assigned_user_id).
     * Для UI badge'а acting'а: «временно от @{owner}».
     */
    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->assigned_user_id === $user->id;
    }

    /**
     * У $user есть активная delegation acting'ом на эту заявку.
     */
    public function isDelegatedTo(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->activeDelegations()
            ->where('acting_user_id', $user->id)
            ->exists();
    }

    /**
     * Может ли $user работать с этой заявкой:
     *  - owner (assigned_user_id) ИЛИ
     *  - active acting (delegation) ИЛИ
     *  - privileged role (head_of_sales / director / secretary).
     *
     * Используется в Detail::canManage / RequestItemEditor::ensureCanEdit /
     * RequestStateService::ensureCanTransition / RequestPauseService::ensureCanPause.
     */
    public function isAccessibleBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        if ($this->isOwnedBy($user)) {
            return true;
        }
        if ($user->hasAnyRole(['head_of_sales', 'director', 'secretary'])) {
            return true;
        }

        return $this->isDelegatedTo($user);
    }
}
