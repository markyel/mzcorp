<?php

namespace App\Models;

use App\Enums\AttentionReason;
use App\Enums\MailDirection;
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
        // peak_status — «дальше всего достигнутый» milestone в lifecycle
        // (см. RequestStatus::lifecycleOrder). Обновляется автоматически
        // в RequestStateService::transitionTo; UI читает через accessor
        // displayedStatus, который выбирает между current и peak.
        'peak_status',
        'client_email',
        'client_name',
        // Контактные поля из заявок с сайта (WebFormSubmissionParser).
        'client_phone',
        'client_company',
        'client_address',
        // Раздел «Клиенты»: точная привязка к организации (Organization).
        // Проставляется RequestOrganizationResolver / clients:backfill,
        // поправима руками. См. миграцию add_organization_id_to_requests_table.
        'organization_id',
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
        // Pool re-sort: denormalized timestamp + тип последнего события.
        'last_activity_at',
        'last_activity_type',
        // Слияние заявок (RequestMergeService).
        'merged_into_id',
        'merged_at',
        // Phase 2.1 — наследование от архивной closed_lost заявки.
        // Заполняется CheckInheritanceJob после LLM-подтверждения
        // гипотезы «новая Request — продолжение архивной».
        // См. RequestInheritanceService (drop-in из LazyLift).
        'inheritance_group_id',
        'inheritance_role',
        'inheritance_parent_id',
        // Сложность (RequestComplexityService) — snapshot входной нагрузки
        // на менеджера. Score = Σ MatchPath::weight(items active). Level
        // выводится по порогам AppSetting `complexity.thresholds`.
        'complexity_score',
        'complexity_level',
        // Агрегированная мета парсинга: dedup_dropped[] (схлопнутые дубли
        // с привязкой к merged_into_position) и attachment_extracted[]
        // (справочная инфа из вложений — серийник, модель, объект, контракт).
        // Заполняется RequestItemPersister + AttachmentMetaExtractionService.
        // См. миграцию add_parsing_meta_to_requests_table.
        'parsing_meta',
    ];

    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'peak_status' => RequestStatus::class,
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
            'last_activity_type' => \App\Enums\RequestActivityType::class,
            'merged_into_id' => 'integer',
            'merged_at' => 'datetime',
            'organization_id' => 'integer',
            'complexity_score' => 'integer',
            'complexity_level' => \App\Enums\ComplexityLevel::class,
            'parsing_meta' => 'array',
        ];
    }

    /**
     * Парсер позиций ещё не завершил работу для этой заявки.
     *
     * Условие:
     *   - status === Pending (заявка не сдвинулась дальше) И
     *   - parsing_meta.parser_finished_at не проставлен ParseRequestItemsJob И
     *   - заявке меньше 30 минут (отсечка для исторических Pending без
     *     parser_finished_at — backfill не делаем, через 30 мин «крутиться»
     *     индикатор перестаёт, менеджер видит «0 позиций» и работает руками).
     *
     * Используется в UI карточки (детальная страница заявки) для рендера
     * chip'а «парсится…» вместо «0 позиций» и для wire:poll'а компонента
     * пока парсер не отработает.
     */
    public function isParsingInFlight(): bool
    {
        $meta = is_array($this->parsing_meta ?? null) ? $this->parsing_meta : [];
        $finished = $meta['parser_finished_at'] ?? null;
        $reparseAt = $meta['reparse_dispatched_at'] ?? null;

        // Ручной reparse через UI: считаем заявку «in flight» пока парсер
        // не завершится ПОСЛЕ диспатча (или 5 минут не пройдут как timeout).
        // Это покрывает заявки в любых статусах — quoted, in_progress, paid…
        // — а не только Pending. Без этого после reparse не появлялся chip
        // «парсится…», не включался wire:poll, пользователю казалось что
        // кнопка не сработала. Кейс M-2026-2102 2026-05-28.
        if ($reparseAt !== null) {
            try {
                $reparseTs = \Carbon\Carbon::parse($reparseAt);
                $finishedTs = $finished !== null ? \Carbon\Carbon::parse($finished) : null;
                $stillRunning = $finishedTs === null || $finishedTs->lt($reparseTs);
                if ($stillRunning && $reparseTs->greaterThan(now()->subMinutes(5))) {
                    return true;
                }
            } catch (\Throwable) {
                // Битый timestamp в meta — игнорируем, идём к default-логике.
            }
        }

        // Default-логика (первоначальный парсинг при создании заявки).
        if ($this->status !== RequestStatus::Pending) {
            return false;
        }
        if ($finished !== null) {
            return false;
        }
        if ($this->created_at === null) {
            return false;
        }
        return $this->created_at->greaterThan(now()->subMinutes(30));
    }

    /**
     * Статус для отображения в UI (чип в пуле, header заявки, дашборд).
     *
     * Логика: state-machine разрешает «откаты» (Quoted → InProgress
     * «возврат на правки» / AwaitingClientClarification при вопросе клиента
     * после КП). Operational status показывает что менеджеру делать сейчас,
     * но визуально полезнее видеть milestone — «КП отправлено» вместо
     * «В работе» или «Жду клиента». peak_status хранит «дошли ли мы хотя
     * бы раз до Quoted/UnderReview/Invoice/Paid/Won».
     *
     * Правила display:
     *   - Terminal (ClosedWon / ClosedLost) или Paused — показываем current
     *     (заявка закрыта / заморожена, чип отражает это состояние).
     *   - Иначе если peak установлен (заявка дошла до Quoted+ хотя бы раз) —
     *     показываем peak. Даже если current откатился в InProgress (правки)
     *     или AwaitingClientClarification (клиент уточняет КП) — milestone
     *     уже достигнут, чип «КП отправлено» точнее operational'а.
     *   - Если peak null (заявка ещё не дошла до Quoted) — показываем
     *     current operational статус (Жду клиента / В работе / Назначена).
     */
    public function getDisplayedStatusAttribute(): RequestStatus
    {
        $current = $this->status;
        if ($current === null) {
            return RequestStatus::Pending;
        }
        if ($current->isTerminal() || $current === RequestStatus::Paused) {
            return $current;
        }
        $peak = $this->peak_status;
        if ($peak instanceof RequestStatus) {
            return $peak;
        }

        return $current;
    }

    /**
     * Бейдж статуса для UI — отображаемый статус по воронке (peak milestone
     * или current).
     *
     * 2026-05-25: разделили со «статусом события». Раньше badge накладывал
     * activity (ClientReplied → «Ответ клиента») поверх chip'а, чтобы дать
     * амбер-сигнал «ход за нами». Это путало: chip показывал «Ответ клиента»,
     * а реальный статус Invoiced («Счёт отправлен») терялся. Менеджеры/РОП
     * сообщали что статус и flag внимания смешаны:
     *
     *   STATUS chip      — куда заявка ДОВЕДЕНА по воронке (Quoted/Invoiced/...)
     *   ACTIVITY row     — последнее событие требующее внимания
     *                      (ClientReplied/SupplierReplied/etc.)
     *
     * Activity row рендерится отдельно в pool.blade.php / detail.blade.php
     * c amber-стилизацией если requiresAttention. RequestActivityType::
     * isRedundantWithStatus() прячет когда дублирует.
     *
     * Shape сохранён для обратной совместимости, но `isAttention` теперь
     * всегда false — chip больше не отражает attention.
     *
     * @return array{label: string, chipClass: string, icon: ?string, isAttention: bool}
     */
    public function getDisplayedStatusBadgeAttribute(): array
    {
        $current = $this->status;
        if ($current === null) {
            return [
                'label' => RequestStatus::Pending->label(),
                'chipClass' => RequestStatus::Pending->chipClass(),
                'icon' => null,
                'isAttention' => false,
            ];
        }
        $displayed = $this->displayedStatus;

        return [
            'label' => $displayed->label(),
            'chipClass' => $displayed->chipClass(),
            'icon' => null,
            'isAttention' => false,
        ];
    }

    /**
     * Заявка, в которую эту слили (если эта — loser слияния).
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /**
     * Заявки, которые слили в эту (если эта — winner слияния).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function mergedFrom()
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    /**
     * Phase 2.1 — наследование. Родитель (архивная закрытая заявка),
     * от которой эта Request наследована.
     */
    public function inheritanceParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'inheritance_parent_id');
    }

    /**
     * Phase 2.1 — наследующие заявки (если эта — родитель).
     *
     * @return HasMany
     */
    public function inheritanceChildren(): HasMany
    {
        return $this->hasMany(self::class, 'inheritance_parent_id');
    }

    /**
     * Эта заявка — родитель в наследовании.
     */
    public function isInheritanceParent(): bool
    {
        return $this->inheritance_role === 'parent';
    }

    /**
     * Эта заявка — наследник от родителя.
     */
    public function isInheritanceChild(): bool
    {
        return $this->inheritance_role === 'child' && ! empty($this->inheritance_parent_id);
    }

    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    /**
     * ID всех входящих писем треда заявки, из вложений которых оператор
     * может выбрать фото для позиции (диалог «Сменить фото»).
     *
     * Включает триггерное письмо (email_message_id) и все последующие
     * inbound reply'и клиента (related_request_id = этой заявке). Фото
     * товара присылает клиент, поэтому outbound (наши Sent — подписи,
     * рендеры КП) и черновики исключены, чтобы не засорять picker
     * логотипами и служебными картинками.
     *
     * Раньше picker и валидация rebindPhoto смотрели только в триггерное
     * письмо — фото, присланные в более позднем письме, были недоступны
     * (кейс M-2026-2257: Vision взял логотип из первого письма, нужное
     * фото пришло позже).
     *
     * @return list<int>
     */
    public function photoSourceMessageIds(): array
    {
        return EmailMessage::query()
            ->where('direction', MailDirection::Inbound->value)
            ->where('is_draft', false)
            ->where(function ($q) {
                $q->where('related_request_id', $this->id);
                if ($this->email_message_id !== null) {
                    $q->orWhere('id', $this->email_message_id);
                }
            })
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Организация-клиент (раздел «Клиенты»). Точная привязка через
     * requests.organization_id; null пока не определена/неоднозначна.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
     * Snapshot'ы исходящих КП/счетов (Foundation §7, расширение).
     * Заполняется ParseOutboundQuoteJob — один snapshot на каждое
     * вложение PDF/XLSX/DOCX отправленного клиенту коммерческого
     * документа.
     */
    public function outboundQuotes(): HasMany
    {
        return $this->hasMany(OutboundQuote::class)->orderByDesc('id');
    }

    /**
     * Наши КП клиенту (Quotation), Hybrid versioning: одна active draft +
     * исторические закреплённые/sent версии. Отсортировано версией убыв.
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class)->orderByDesc('version');
    }

    /**
     * Счета по заявке (Phase 4). Может быть несколько — после expire/cancel
     * менеджер может перевыставить новый. Сортировка id desc — последний сверху.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderByDesc('id');
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
        if ($user->hasAnyRole(['head_of_sales', 'director', 'secretary', 'admin'])) {
            return true;
        }

        return $this->isDelegatedTo($user);
    }
}
