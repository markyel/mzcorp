<?php

namespace App\Livewire\Requests;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\RequestItem;
use App\Models\AiDecision;
use App\Services\Catalog\RequestItemEditor;
use App\Services\DocumentDetector\AiDecisionService;
use App\Services\Request\RequestPauseService;
use App\Services\Request\RequestStateService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Карточка заявки (Phase 1.8d + Phase 1.9 inbound thread).
 *
 * Менеджер видит только свои; РОП/директор/секретарь — все.
 *
 * UI разбит на 7 табов по `design/ui_kits/crm/04-request-detail.html`:
 * Обзор / Переписка / Позиции / Поставщики / Активность / Файлы / Связанные.
 *
 * Phase 1.9: таб «Переписка» рендерит весь thread — все EmailMessage с
 * related_request_id == request->id, отсортированные по sent_at.
 * Изначально single trigger-email; reply'и присоединяются через
 * `App\Services\Mail\InboundReplyLinker` в `MailRouter::route`.
 *
 * Поля sticky/SLA/сумма/сматчено и табы Поставщики/Связанные пока
 * рендерят placeholder «Phase 2», т.к. данных в БД ещё нет.
 */
class Detail extends Component
{
    public const TABS = ['overview', 'thread', 'items', 'quotes', 'invoices', 'suppliers', 'activity', 'files', 'related'];

    public Request $request;

    /** @var Collection<int, EmailMessage> */
    public Collection $thread;

    #[Url(as: 'tab')]
    public string $tab = 'overview';

    /**
     * Персональный порядок писем в табе «Переписка»: 'asc' (старые сверху,
     * дефолт) / 'desc' (новые сверху, как в почтовых клиентах). Инициализируется
     * из users.thread_sort_order, переключается кнопкой и сохраняется per-user.
     */
    public string $threadSort = 'asc';

    /**
     * Priority 1: toggle «Показать удалённые позиции» в табе «Позиции».
     * Soft-deleted items (is_active=false) по умолчанию скрыты, в hero
     * не учитываются. При toggle=true рендерим серой строкой с кнопкой
     * «Восстановить».
     */
    public bool $showDeletedItems = false;

    /**
     * Toggle: вывести позиции sticky-связанных заявок (forward + reverse)
     * вторым блоком в табе «Позиции» — для контекста «что клиент уже
     * спрашивал в предыдущих заявках».
     */
    public bool $includeStickyItems = false;

    /**
     * Foundation §6.2 комбо-режим: map item_id => true для позиций
     * раскрытых в expanded-вид (со slot-grid, quick-chips, free-text
     * textarea, history). Остальные позиции — compact-row.
     *
     * @var array<int, bool>
     */
    public array $expandedPositions = [];

    /**
     * Phase E.2: менеджер скрыл AI-баннер вручную. Состояние в Livewire-
     * сессии, ресетится при reload страницы.
     */
    public bool $aiBannerHidden = false;

    public function mount(Request $request): void
    {
        $user = auth()->user();

        // Доступ к карточке: владелец (assigned_user_id), acting-менеджер через
        // активную delegation, ИЛИ привилегированная роль (РОП/директор/секретарь/
        // админ). isAccessibleBy покрывает все три случая. Баг: раньше гейт
        // проверял только assigned_user_id, поэтому делегированная заявка —
        // показанная acting-менеджеру в пуле — на входе отбивалась 403
        // «назначена другому менеджеру».
        if (! $request->isAccessibleBy($user)) {
            abort(403, 'Эта заявка назначена другому менеджеру.');
        }

        // Персональный порядок писем в «Переписке».
        $this->threadSort = in_array($user?->thread_sort_order, ['asc', 'desc'], true)
            ? $user->thread_sort_order
            : 'asc';

        $this->request = $request->load([
            'assignedUser:id,name,email,avatar_neutral_path,avatar_won_path,avatar_lost_path',
            'items' => fn ($q) => $this->applyItemsFilter($q)->withCount('photos')->orderBy('position'),
            'items.brand:id,name',
            'items.kbCategory:id,slug,name',
            'items.imageAttachment:id,email_message_id,filename,mime_type,disk,file_path,size_bytes',
            'items.catalogItem:id,sku,name,brand,brand_article,price,stock_available,is_active,last_imported_at',
            // Foundation §6.2 — UI-история вопросов клиенту per item.
            'items.clarificationQuestions' => fn ($q) => $q->orderByDesc('id'),
            'items.clarificationQuestions.batch:id,status,sent_at,answered_at,created_by_user_id',
            'items.clarificationQuestions.batch.createdBy:id,name',
            'clarificationBatches' => fn ($q) => $q->orderByDesc('id'),
            'clarificationBatches.createdBy:id,name',
            'clarificationBatches.questions:id,batch_id,request_item_id,answered_at',
            'context:id,request_id,analysis_status,equipment_units,llm_model_version,analyzed_at',
            'emailMessage.attachments:id,email_message_id,filename,size_bytes,mime_type,content_id,is_inline',
            'emailMessage.mailbox:id,email,name',
            'assignments' => fn ($q) => $q->orderByDesc('assigned_at'),
            'assignments.user:id,name',
            'assignments.assignedBy:id,name',
            // Phase 1.10 — state-changes для таба «Активность».
            'stateChanges' => fn ($q) => $q->orderByDesc('created_at'),
            'stateChanges.byUser:id,name',
            // Phase 7 — snapshot'ы отправленных КП/счетов. В таб «КП»
            // загружаем полные item'ы + catalog/request relations для drill-down.
            // Таб «КП» отображает только КП-документы. Распарсенные
            // исходящие счета (document_type=outbound_invoice) автоматически
            // оборачиваются в Invoice (InvoiceService::autoIssueFromOutboundQuote)
            // и показываются на отдельном табе «Счета» + в /dashboard/invoices.
            'outboundQuotes' => fn ($q) => $q->where('status', \App\Models\OutboundQuote::STATUS_MATCHED)
                ->where(function ($qq) {
                    $qq->whereNull('document_type')
                        ->orWhere('document_type', '!=', \App\Enums\DetectorType::OutboundInvoice->value);
                })
                ->orderByDesc('id'),
            'outboundQuotes.items' => fn ($q) => $q->orderBy('position'),
            'outboundQuotes.items.catalogItem:id,sku,name,brand,brand_article,price',
            'outboundQuotes.items.requestItem:id,position,parsed_name,parsed_article,parsed_qty,is_active',
            'outboundQuotes.attachment:id,email_message_id,filename,mime_type,size_bytes,disk,file_path',
            // Reverse-side: для chip «📨 в КП» в каждой строке таба «Позиции».
            // Берём только из matched quote'ов (failed/parsing скрываем).
            'items.outboundQuoteItems' => fn ($q) => $q->whereHas(
                'quote',
                fn ($qq) => $qq->where('status', \App\Models\OutboundQuote::STATUS_MATCHED)
            ),
        ]);

        // Полный тред: все письма прицепленные к заявке (trigger + reply'и),
        // отсортированы по sent_at для естественной хронологии. NULL sent_at
        // (редкие письма без Date header) уходят в конец.
        // Phase 1.9: visibleTo фильтрует чужие черновики (свои показываются).
        $this->thread = EmailMessage::query()
            ->where('related_request_id', $this->request->id)
            // Скрываем cross-mailbox копии — то же письмо, доставленное
            // в личный ящик менеджера через DeliverToManagerInboxJob
            // (detected_artifacts.cross_mailbox_copy_of). Показываем
            // только оригинал, чтобы в треде не дублировалось.
            ->whereRaw("(detected_artifacts->>'cross_mailbox_copy_of') IS NULL")
            ->visibleTo($user)
            ->with([
                'attachments:id,email_message_id,filename,size_bytes,mime_type,content_id,is_inline',
                'mailbox:id,email,name',
            ])
            ->orderByRaw('sent_at IS NULL, sent_at ASC')
            ->orderBy('id')
            ->get();

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }

        // Attention «📨 Ответ от клиента» — менеджер открыл карточку, фиксируем
        // last_seen_at и снимаем ClientReplied (recompute вернёт SlaBreach по
        // статусу или null). Не делаем для secretary — он не «менеджер»
        // заявки, только просматривает; и не для гостей (тогда mount абортит
        // выше).
        if ($user !== null && ! $user->hasRole(Role::Secretary->value)) {
            \App\Models\RequestUserView::updateOrCreate(
                ['request_id' => $this->request->id, 'user_id' => $user->id],
                ['last_seen_at' => now()],
            );

            // Гейт «реально работает с заявкой» — owner или acting через
            // delegation. РОП/директор/admin смотрят как наблюдатели и
            // НЕ должны двигать статус / снимать attention-флаги.
            // (isAccessibleBy включает privileged-роли — здесь это
            // неподходящая семантика.)
            $isHandler = $this->request->isOwnedBy($user)
                || $this->request->isDelegatedTo($user);

            if ($isHandler) {
                app(\App\Services\Request\AttentionService::class)
                    ->onManagerOpened($this->request);
            }

            // Implicit-state: ответственный менеджер (или acting через
            // delegation) открыл заявку — значит он начал работу. Без
            // кнопки «Начать работу» — статус подсасывается по факту.
            // РОП/директор-наблюдатель НЕ триггерит — они просто смотрят.
            if (
                $isHandler
                && in_array($this->request->status, [RequestStatus::Assigned, RequestStatus::New], true)
            ) {
                try {
                    app(\App\Services\Request\RequestStateService::class)
                        ->transitionTo(
                            $this->request,
                            RequestStatus::InProgress,
                            $user,
                            ['event' => 'auto_first_open'],
                        );
                    $this->request = $this->request->fresh();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        'Detail::mount auto-transition InProgress failed (non-fatal)',
                        ['request_id' => $this->request->id, 'user_id' => $user->id, 'error' => $e->getMessage()],
                    );
                }
            }
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    /**
     * Открыть форму ответа клиенту. ComposeForm живёт внутри таба «Переписка»
     * (только там зарегистрирован), поэтому action из action-panel (любой таб):
     *  1) переключает $tab на 'thread' — ComposeForm рендерится;
     *  2) диспатчит open-reply/open-compose — ComposeForm ловит event
     *     после DOM-обновления (Livewire выполняет dispatch'ы после patch'а).
     *
     * @param  ?int  $messageId  Если есть — открыть как Reply на конкретное
     *                           inbound-сообщение; иначе compose-blank.
     */
    public function composeReply(?int $messageId = null): void
    {
        $this->tab = 'thread';
        if ($messageId) {
            $this->dispatch('open-reply', messageId: $messageId, requestId: $this->request->id);
        } else {
            $this->dispatch('open-compose', requestId: $this->request->id);
        }
    }

    /**
     * Phase 2: применить LLM-предположение «это уточнение существующей
     * позиции» — дописать additional_article к parsed_article + (опц.)
     * brand, удалить запись из pending_clarifications.
     *
     * Идемпотентность: если артикул уже присутствует в parsed_article
     * (по normalizeArticle-эквиваленту), просто чистим очередь.
     *
     * Privileged-роли + владелец заявки.
     */
    public function applyClarification(string $clarificationId): void
    {
        $this->mutateClarification($clarificationId, apply: true);
    }

    /**
     * Phase 2: отклонить LLM-предположение — удаление из очереди без
     * правки позиции.
     */
    public function rejectClarification(string $clarificationId): void
    {
        $this->mutateClarification($clarificationId, apply: false);
    }

    private function mutateClarification(string $clarificationId, bool $apply): void
    {
        $user = auth()->user();
        // Доступ: владелец, acting через delegation, или привилегированный
        // (isAccessibleBy покрывает все три). Раньше проверялся только
        // assigned_user_id — acting-менеджер не мог применять/отклонять уточнения.
        if (! $this->request->isAccessibleBy($user)) {
            abort(403);
        }

        $req = $this->request->fresh(['items']);
        if ($req === null) {
            return;
        }
        $queue = is_array($req->pending_clarifications) ? $req->pending_clarifications : [];

        $target = null;
        $remaining = [];
        foreach ($queue as $entry) {
            if (is_array($entry) && ($entry['id'] ?? null) === $clarificationId && $target === null) {
                $target = $entry;
                continue;
            }
            $remaining[] = $entry;
        }
        if ($target === null) {
            // Запись могла быть удалена параллельно — просто релоудим.
            $this->reloadRequest();

            return;
        }

        if ($apply) {
            $item = $req->items->firstWhere('position', (int) ($target['target_position'] ?? 0));
            if ($item !== null) {
                $addArt = $target['additional_article'] ?? null;
                $addBrand = $target['additional_brand'] ?? null;
                $dirty = false;

                if (is_string($addArt) && $addArt !== '') {
                    $existingArt = (string) ($item->parsed_article ?? '');
                    if (! $this->articleAlreadyPresent($existingArt, $addArt)) {
                        $item->parsed_article = $existingArt === ''
                            ? $addArt
                            : $existingArt . ', ' . $addArt;
                        $dirty = true;
                    }
                }
                if (is_string($addBrand) && $addBrand !== '' && empty($item->parsed_brand)) {
                    $item->parsed_brand = $addBrand;
                    $dirty = true;
                }
                if ($dirty) {
                    $item->save();
                }
            }
        }

        $req->forceFill([
            'pending_clarifications' => empty($remaining) ? null : array_values($remaining),
        ])->save();

        \Illuminate\Support\Facades\Log::info('Detail: clarification mutated', [
            'request_id' => $req->id,
            'clarification_id' => $clarificationId,
            'action' => $apply ? 'apply' : 'reject',
            'target_position' => $target['target_position'] ?? null,
            'user_id' => $user?->id,
        ]);

        $this->reloadRequest();
    }

    private function reloadRequest(): void
    {
        $this->request = Request::query()
            ->whereKey($this->request->id)
            ->with([
                'assignedUser:id,name,email,avatar_neutral_path,avatar_won_path,avatar_lost_path',
                'items' => fn ($q) => $this->applyItemsFilter($q)->withCount('photos')->orderBy('position'),
                'items.brand:id,name',
                'items.kbCategory:id,slug,name',
                'items.imageAttachment:id,email_message_id,filename,mime_type,disk,file_path,size_bytes',
                'items.catalogItem:id,sku,name,brand,brand_article,price,stock_available,is_active,last_imported_at',
                'items.clarificationQuestions' => fn ($q) => $q->orderByDesc('id'),
                'items.clarificationQuestions.batch:id,status,sent_at,answered_at,created_by_user_id',
                'items.clarificationQuestions.batch.createdBy:id,name',
                'clarificationBatches' => fn ($q) => $q->orderByDesc('id'),
                'clarificationBatches.createdBy:id,name',
                'clarificationBatches.questions:id,batch_id,request_item_id,answered_at',
                'context:id,request_id,analysis_status,equipment_units,llm_model_version,analyzed_at',
                'emailMessage.attachments:id,email_message_id,filename,size_bytes,mime_type,content_id,is_inline',
                'emailMessage.mailbox:id,email,name',
                'assignments' => fn ($q) => $q->orderByDesc('assigned_at'),
                'assignments.user:id,name',
                'assignments.assignedBy:id,name',
                'stateChanges' => fn ($q) => $q->orderByDesc('created_at'),
                'stateChanges.byUser:id,name',
            ])
            ->firstOrFail();
    }

    /**
     * Фильтр items в зависимости от toggle «Показать удалённые».
     * По умолчанию — только активные (is_active=true).
     */
    private function applyItemsFilter($query)
    {
        if ($this->showDeletedItems) {
            return $query; // все, включая is_active=false
        }
        return $query->where('is_active', true);
    }

    /**
     * Проверка наличия артикула в строке существующих артикулов
     * (`A, B, C` или одиночный). Та же нормализация что и в
     * `RequestItemParsingService::normalizeArticle` (без пробелов/тире,
     * upper-case) — чтобы повторное Apply не плодило дубль вида
     * «M21595, m-21595».
     */
    private function articleAlreadyPresent(string $existing, string $candidate): bool
    {
        $norm = fn (string $s) => preg_replace('/[\s\-_.\/]/', '', mb_strtoupper(trim($s)));
        $candidateNorm = $norm($candidate);
        if ($candidateNorm === '') {
            return true;
        }
        foreach (preg_split('/\s*,\s*/', $existing) as $part) {
            if ($norm((string) $part) === $candidateNorm) {
                return true;
            }
        }

        return false;
    }

    /**
     * Snapshot sticky-привязок (Phase 2 sticky visibility).
     *
     * Самый свежий `request_assignments` с reason, начинающимся на
     * `auto_sticky` (свежий — на случай ручного переподчинения через
     * ReassignService, чтобы исторический sticky-snapshot не терялся).
     * Парсим payload: `auto_sticky:{"kind":"catalog|client|text","linked":[id1,...]}`.
     *
     * Старые backfilled-записи имеют просто `auto_sticky` (без `:`-payload) —
     * legacy=true, links пустой, kind=null; UI покажет общий чип без deep-links.
     *
     * @return array{links: \Illuminate\Support\Collection, legacy: bool, kind: ?string}
     */
    #[Computed]
    public function sticky(): array
    {
        $assignment = $this->request->assignments
            ->first(fn ($a) => str_starts_with((string) $a->reason, 'auto_sticky'));

        if (! $assignment) {
            return ['links' => collect(), 'legacy' => false, 'kind' => null];
        }

        $reason = (string) $assignment->reason;
        $colonAt = strpos($reason, ':');
        if ($colonAt === false) {
            // legacy: 'auto_sticky' без payload (165 backfill).
            return ['links' => collect(), 'legacy' => true, 'kind' => null];
        }

        $payload = substr($reason, $colonAt + 1);
        $data = json_decode($payload, true);
        $kind = is_array($data) ? ($data['kind'] ?? null) : null;
        $ids = is_array($data['linked'] ?? null) ? $data['linked'] : [];
        if (empty($ids)) {
            // Payload есть, но linked пустой (раньше mы такого писали при
            // некоторых corner-case'ах). Считаем legacy, но kind сохраняем
            // если он там был.
            return ['links' => collect(), 'legacy' => true, 'kind' => $kind];
        }

        $links = \App\Models\Request::query()
            ->whereIn('id', $ids)
            ->orderByDesc('created_at')
            ->get(['id', 'internal_code', 'subject', 'status', 'client_name']);

        return ['links' => $links, 'legacy' => false, 'kind' => $kind];
    }

    /**
     * Двунаправленные sticky-связи для hero-блока (навигация в обе стороны):
     *  - forward: ЭТА заявка прилеплена к другой (её id в нашем auto_sticky.linked);
     *  - reverse: ДРУГАЯ заявка прилеплена к этой (наш id в её auto_sticky.linked).
     *
     * Лёгкая выборка (без позиций) — только для перехода между связанными
     * заявками. Полные позиции связанных грузит relatedStickyRequests() (таб «Позиции»).
     *
     * @return \Illuminate\Support\Collection<int, array{request: \App\Models\Request, forward: bool, reverse: bool}>
     */
    #[Computed]
    public function stickyConnections()
    {
        $thisId = (int) $this->request->id;
        $forwardIds = array_map('intval', $this->sticky['links']->pluck('id')->all());

        // Reverse: заявки, в auto_sticky.linked которых упомянут наш id.
        // JSON-as-string в reason — подстрочное ILIKE (как в relatedStickyRequests).
        $reverseIds = \App\Models\RequestAssignment::query()
            ->where('reason', 'like', 'auto_sticky:%')
            ->where(function ($q) use ($thisId) {
                $q->where('reason', 'like', '%"linked":[' . $thisId . ']%')
                    ->orWhere('reason', 'like', '%"linked":[' . $thisId . ',%')
                    ->orWhere('reason', 'like', '%,' . $thisId . ',%')
                    ->orWhere('reason', 'like', '%,' . $thisId . ']%');
            })
            ->pluck('request_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn ($id) => $id === $thisId)
            ->values()
            ->all();

        $ids = array_values(array_unique(array_merge($forwardIds, $reverseIds)));
        if (empty($ids)) {
            return collect();
        }

        $forwardSet = array_flip($forwardIds);
        $reverseSet = array_flip($reverseIds);

        return \App\Models\Request::query()
            ->whereIn('id', $ids)
            ->where('id', '!=', $thisId)
            ->orderByDesc('created_at')
            ->get(['id', 'internal_code', 'subject', 'status', 'client_name'])
            ->map(fn ($r) => [
                'request' => $r,
                'forward' => isset($forwardSet[(int) $r->id]),
                'reverse' => isset($reverseSet[(int) $r->id]),
            ]);
    }

    /**
     * Phase 2.1 inheritance: Map<request_item_id, RequestItemLink>
     * для текущей заявки.
     *
     *  - child:  ключ — id child-item, link.parentItem указывает на
     *            архивную родительскую позицию.
     *  - parent: ключ — id parent-item, link.childItem указывает на
     *            позицию в наследующей заявке (для отображения
     *            «→ продолжена в M-NNNN»).
     *
     * Возвращает пустую коллекцию если заявка не участвует в наследовании.
     */
    #[Computed]
    public function inheritanceItemLinks(): \Illuminate\Support\Collection
    {
        $itemIds = $this->request->items->pluck('id');
        if ($itemIds->isEmpty()) {
            return collect();
        }

        if ($this->request->isInheritanceChild()) {
            return \App\Models\RequestItemLink::query()
                ->active()
                ->whereIn('child_item_id', $itemIds)
                ->with(['parentItem' => fn ($q) => $q->select('id', 'request_id', 'position', 'parsed_name', 'parsed_article', 'parsed_qty', 'parsed_unit')])
                ->with(['parentItem.request:id,internal_code,status'])
                ->get()
                ->keyBy('child_item_id');
        }

        if ($this->request->isInheritanceParent()) {
            return \App\Models\RequestItemLink::query()
                ->active()
                ->whereIn('parent_item_id', $itemIds)
                ->with(['childItem' => fn ($q) => $q->select('id', 'request_id', 'position', 'parsed_qty', 'parsed_unit')])
                ->with(['childItem.request:id,internal_code,status'])
                ->get()
                ->groupBy('parent_item_id');
        }

        return collect();
    }

    /**
     * Phase 2.3 — ручная реанимация closed_lost заявки.
     *
     * Используется когда менеджер ОСОЗНАННО хочет вернуть заявку в работу
     * (типичный кейс: клиент молчал после КП → передумал, попросил обновить
     * счёт; auto-реанимация отключена в Phase 1). В отличие от auto-flow:
     *   - не пересматривает assignee (assigned_user_id остаётся как был);
     *   - не требует source email (менеджер инициирует сам);
     *   - audit event=`manual_reanimate` (для отличия в Activity).
     *
     * Permission: owner / acting (delegation) / privileged
     * (head_of_sales, director, admin). Секретарь — нет (он только наблюдатель).
     */
    public function manualReanimate(\App\Services\Request\RequestStateService $service): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(403);
        }
        if ($user->hasRole(\App\Enums\Role::Secretary->value)) {
            $this->dispatch('toast', message: 'Секретарь только просматривает заявки.', type: 'error');
            return;
        }

        $privileged = $user->hasAnyRole([
            \App\Enums\Role::HeadOfSales->value,
            \App\Enums\Role::Director->value,
            \App\Enums\Role::Admin->value,
        ]) ?? false;
        if (! $privileged && ! $this->request->isAccessibleBy($user)) {
            $this->dispatch('toast', message: 'Нет прав реанимировать.', type: 'error');
            return;
        }

        if ($this->request->status !== RequestStatus::ClosedLost) {
            $this->dispatch('toast', message: 'Реанимировать можно только закрытые с потерей.', type: 'error');
            return;
        }

        try {
            $service->reanimate(
                $this->request,
                $user,
                sourceMessage: null,
                reassessAssignee: false,
                event: 'manual_reanimate',
                comment: 'Ручная реанимация менеджером',
            );
            $this->reloadRequest();
            $this->dispatch('toast', message: 'Заявка реанимирована. Менеджер сохранён.', type: 'success');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Detail::manualReanimate failed', [
                'request_id' => $this->request->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('toast', message: 'Не удалось реанимировать: ' . $e->getMessage(), type: 'error');
        }
    }

    /**
     * Phase 2.1 — отвязать наследование (только для child-заявок).
     *
     * Permission: owner / acting (delegation) / privileged
     * (head_of_sales, director, admin). Менеджер может ошибочно
     * связанную child-заявку отвязать от parent. Item-links
     * деактивируются (история сохраняется).
     */
    public function unlinkInheritance(\App\Services\Request\RequestInheritanceService $service): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(403);
        }

        $privileged = $user->hasAnyRole([
            \App\Enums\Role::HeadOfSales->value,
            \App\Enums\Role::Director->value,
            \App\Enums\Role::Admin->value,
        ]) ?? false;
        if (! $privileged && ! $this->request->isAccessibleBy($user)) {
            $this->dispatch('toast', message: 'Нет прав отвязать наследование.', type: 'error');
            return;
        }

        if (! $this->request->isInheritanceChild()) {
            $this->dispatch('toast', message: 'Эта заявка не является наследником.', type: 'error');
            return;
        }

        $parentCode = $this->request->inheritanceParent?->internal_code;
        try {
            $service->unlinkChild(
                $this->request,
                reason: 'manual',
                unlinkedBy: (string) ($user->email ?? $user->id),
            );
            $this->reloadRequest();
            $this->dispatch('toast', message: 'Наследование от ' . ($parentCode ?: 'архивной') . ' отвязано.', type: 'success');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Detail::unlinkInheritance failed', [
                'request_id' => $this->request->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('toast', message: 'Не удалось отвязать: ' . $e->getMessage(), type: 'error');
        }
    }

    /**
     * Подписи и счётчики для табов. `null` = без счётчика.
     *
     * @return array<string, array{label: string, count: ?int, disabled: bool}>
     */
    #[Computed]
    public function tabs(): array
    {
        $threadCount = $this->thread->count();
        $items = $this->request->items->count();
        $files = $this->thread->reduce(
            fn (int $carry, EmailMessage $msg) => $carry + $msg->attachments->count(),
            0,
        );
        // Phase 7: учесть attachment'ы OutboundQuote'ов, которые могли не попасть
        // в $thread (если письмо с PDF не привязано к заявке как related_request_id).
        $threadAttIds = $this->thread->flatMap(fn ($m) => $m->attachments)->pluck('id')->all();
        $extraQuoteAtts = $this->request->outboundQuotes
            ->pluck('attachment')
            ->filter()
            ->reject(fn ($a) => in_array($a->id, $threadAttIds, true))
            ->count();
        $files += $extraQuoteAtts;
        // Phase 1.10 + расширение: activity = assignments + stateChanges
        // + ВСЕ письма треда (не только initial).
        $activity = $this->request->assignments->count()
            + $this->request->stateChanges->count()
            + $this->thread->count();

        // Snapshot'ы исходящих КП/счетов (Foundation §7 расширение) + наши
        // КП (Quotation, исходящие созданные через QuotationEditor). Counter —
        // суммарное число; таб виден если есть хоть один источник.
        $outboundQuotesCount = $this->request->outboundQuotes->count();
        $quotationsCount = $this->request->quotations()->whereNotIn('status', ['cancelled'])->count();
        $quotesCount = $outboundQuotesCount + $quotationsCount;

        // Phase 4: счета — состояние таба для визуальной подсветки.
        //   overdue — есть pending счёт с истёкшим expires_at (красный)
        //   pending — есть pending без overdue (amber)
        //   paid    — есть только финальные (paid/cancelled/expired), нет pending (emerald)
        //   null    — счетов нет
        $invoices = $this->invoicesForRequest;
        $invCount = $invoices->count();
        $invState = null;
        if ($invCount > 0) {
            $hasOverdue = $invoices->contains(fn ($i) => $i->status?->value === 'pending' && $i->expires_at?->isPast());
            $hasPending = $invoices->contains(fn ($i) => $i->status?->value === 'pending');
            $hasPaid = $invoices->contains(fn ($i) => $i->status?->value === 'paid');
            $invState = match (true) {
                $hasOverdue => 'overdue',
                $hasPending => 'pending',
                $hasPaid    => 'paid',
                default     => 'closed',
            };
        }

        // Фаза 3.2: число разосланных запросов поставщикам (бейдж на табе,
        // чтобы было видно без захода в таб).
        $supplierInquiriesCount = \App\Models\SupplierInquiry::query()
            ->where('related_request_id', $this->request->id)->count();

        // Таб «КП» всегда виден — без него менеджер не может создать первый
        // черновик КП через QuotationEditor. Counter null при нуле (чтобы
        // не показывать «КП 0»).
        $tabs = [
            'overview'  => ['label' => 'Обзор',      'count' => null,         'disabled' => false],
            'thread'    => ['label' => 'Переписка',  'count' => $threadCount, 'disabled' => false],
            'items'     => ['label' => 'Позиции',    'count' => $items,       'disabled' => false],
            'quotes'    => ['label' => 'КП',         'count' => $quotesCount > 0 ? $quotesCount : null, 'disabled' => false],
            'invoices'  => ['label' => 'Счета',      'count' => $invCount > 0 ? $invCount : null, 'disabled' => false, 'state' => $invState],
            'suppliers' => ['label' => 'Поставщики', 'count' => $supplierInquiriesCount > 0 ? $supplierInquiriesCount : null, 'disabled' => false],
            'activity'  => ['label' => 'Активность', 'count' => $activity,    'disabled' => false],
            'files'     => ['label' => 'Файлы',      'count' => $files,       'disabled' => false],
            'related'   => ['label' => 'Связанные',  'count' => null,         'disabled' => true],
        ];

        return $tabs;
    }

    /**
     * Заменить cid:NNN в src/href HTML body на наш inline-роут + свернуть
     * `<blockquote type="cite">` в `<details>` (по умолчанию закрыт).
     *
     * Сворачивание цитат в CRM-треде: иначе при ответе менеджера на письмо
     * Liftway/корп-системы плашка оригинала разворачивается на пол-экрана
     * и затрудняет чтение. Auto-resize iframe (ResizeObserver в шаблоне)
     * подтянет высоту при раскрытии цитаты пользователем.
     */
    public function bodyHtmlFor(EmailMessage $email): ?string
    {
        if (! $email->body_html) {
            return null;
        }

        $messageId = $email->id;

        $html = preg_replace_callback(
            '/(src|href)\s*=\s*(["\'])cid:([^"\']+)\2/i',
            function ($m) use ($messageId) {
                $url = route('attachments.inline', [
                    'emailMessage' => $messageId,
                    'contentId' => rawurlencode($m[3]),
                ]);

                return $m[1] . '=' . $m[2] . $url . $m[2];
            },
            $email->body_html
        ) ?? $email->body_html;

        return $this->collapseQuotedBlocks($html);
    }

    /**
     * Обернуть верхнеуровневые quoted-блоки в `<details>` для сворачивания
     * цитат в CRM-треде. Вложенные не трогаем — они рендерятся внутри
     * родительского details.
     *
     * Покрываем форматы:
     *   - `<blockquote type="cite">`  — Apple Mail / iOS Mail / наш MailQuoteBuilder
     *   - `<blockquote class*="gmail_quote">` — Gmail
     *   - `<blockquote class*="cite">` — Yandex / некоторые корп-системы
     *   - `<blockquote>` без атрибутов — общий fallback
     *   - `<div class*="gmail_quote">`  — Gmail обёртка
     *   - `<div class*="yahoo_quoted">` — Yahoo
     *   - Outlook/корп-системы БЕЗ blockquote: блок «From:/Sent:/To:» или
     *     «От:/Отправлено:/Кому:» (или «--- Original Message ---»), после
     *     которого оригинал письма идёт обычным текстом до конца.
     */
    private function collapseQuotedBlocks(string $html): string
    {
        if (stripos($html, '<blockquote') === false
            && stripos($html, 'gmail_quote') === false
            && stripos($html, 'yahoo_quoted') === false
            && preg_match('/(From|От|Von)\s*:/iu', $html) !== 1
            && stripos($html, 'Original Message') === false
            && stripos($html, 'Исходное сообщение') === false) {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        // Префикс для UTF-8 + корневой контейнер, чтобы saveHTML мог обойти
        // только наши дочерние ноды без `<html><body>` обёртки.
        $wrapped = '<?xml encoding="UTF-8"?><div id="mylift-thread-root">' . $html . '</div>';
        $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return $html;
        }

        $xpath = new \DOMXPath($doc);

        // Порядок важен: сначала Outlook-стиль (header + всё после него) —
        // тогда blockquote-проход пропустит цитаты, уже попавшие внутрь details.
        $changed = $this->collapseOutlookStyleQuote($doc, $xpath);
        $changed = $this->collapseBlockquoteNodes($doc, $xpath) || $changed;

        if (! $changed) {
            return $html;
        }

        $root = $doc->getElementById('mylift-thread-root');
        if (! $root) {
            return $html;
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    /**
     * Outlook-стиль цитирования: `<blockquote>` нет вовсе — реплай-хедер
     * («From: … Sent: … To: …» / «От: … Отправлено: … Кому: …» /
     * «--- Original Message ---») и следом оригинал письма обычными
     * абзацами до конца. Находим ПЕРВЫЙ такой хедер, поднимаемся до уровня,
     * где перед ним есть собственный контент письма, и сворачиваем узел +
     * все последующие siblings. Если собственного контента нет (форвард
     * целиком) — не сворачиваем: читать было бы нечего.
     */
    private function collapseOutlookStyleQuote(\DOMDocument $doc, \DOMXPath $xpath): bool
    {
        $candidates = $xpath->query(
            '//p[not(ancestor::blockquote) and not(ancestor::details)]'
            . ' | //div[not(ancestor::blockquote) and not(ancestor::details)]'
        );
        if ($candidates === false || $candidates->length === 0) {
            return false;
        }

        $header = null;
        foreach ($candidates as $el) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $el->textContent ?? ''));
            if ($text === '' || mb_strlen($text) > 600) {
                continue;
            }
            if ($this->looksLikeReplyHeader($text)) {
                $header = $el;
                break; // первый в document order — самая внешняя цитата
            }
        }
        if ($header === null) {
            return false;
        }

        // Поднимаемся, пока на уровне нет содержимого ПЕРЕД узлом: хедер часто
        // завёрнут в div>div>div, а цитируемый текст — sibling внешней обёртки.
        $node = $header;
        while ($node->parentNode instanceof \DOMElement
            && $node->parentNode->getAttribute('id') !== 'mylift-thread-root'
            && ! $this->hasMeaningfulPrecedingSibling($node)) {
            $node = $node->parentNode;
        }
        if (! $this->hasMeaningfulPrecedingSibling($node)) {
            return false;
        }

        $nodes = [$node];
        for ($n = $node->nextSibling; $n !== null; $n = $n->nextSibling) {
            $nodes[] = $n;
        }
        $this->wrapInDetails($doc, $nodes);

        return true;
    }

    /** Прежний проход: blockquote + Gmail/Yahoo обёртки. */
    private function collapseBlockquoteNodes(\DOMDocument $doc, \DOMXPath $xpath): bool
    {
        // Расширенный selector: любые top-level blockquote + Gmail/Yahoo div'ы.
        // ancestor::blockquote / ancestor::details — чтобы не оборачивать
        // повторно при перерендере (и не трогать цитаты внутри Outlook-details).
        $nodes = $xpath->query(
            '//blockquote[not(ancestor::blockquote) and not(ancestor::details)]'
            . ' | //div[contains(@class, "gmail_quote") and not(ancestor::blockquote) and not(ancestor::details)]'
            . ' | //div[contains(@class, "yahoo_quoted") and not(ancestor::blockquote) and not(ancestor::details)]'
        );
        if ($nodes === false || $nodes->length === 0) {
            return false;
        }

        foreach ($nodes as $bq) {
            /** @var \DOMElement $bq */
            // Yandex web UI кладёт attribution-header'ы (Кому: / Тема: /
            // DATE, NAME <email>:) ПЕРЕД blockquote отдельными div/p
            // блоками — эти строки логически принадлежат цитате. Затягиваем
            // в details все «attribution-like» соседи непосредственно
            // перед blockquote (+ пустые text-nodes и <br>).
            $attributionNodes = [];
            $prev = $bq->previousSibling;
            while ($prev !== null) {
                if ($this->looksLikeQuoteAttribution($prev)) {
                    $attributionNodes[] = $prev;
                    $prev = $prev->previousSibling;
                    continue;
                }
                if (($prev->nodeType === XML_TEXT_NODE && trim($prev->textContent) === '')
                    || ($prev->nodeType === XML_ELEMENT_NODE && strtolower($prev->nodeName) === 'br')
                ) {
                    $attributionNodes[] = $prev;
                    $prev = $prev->previousSibling;
                    continue;
                }
                break;
            }

            $this->wrapInDetails($doc, array_merge(array_reverse($attributionNodes), [$bq]));
        }

        return true;
    }

    /**
     * Завернуть последовательность нод в `<details>` со стандартным summary
     * «показать цитату». details встаёт на место первой ноды, остальные
     * переезжают внутрь в исходном порядке.
     *
     * @param  array<int, \DOMNode>  $nodes
     */
    private function wrapInDetails(\DOMDocument $doc, array $nodes): void
    {
        $first = $nodes[0] ?? null;
        if ($first === null || $first->parentNode === null) {
            return;
        }

        $details = $doc->createElement('details');
        $details->setAttribute('style', 'margin-top:6px;');

        $summary = $doc->createElement('summary', '· · · показать цитату');
        $summary->setAttribute(
            'style',
            'cursor:pointer;list-style:none;font-size:12px;color:#7280a0;'
            . 'user-select:none;padding:2px 0;outline:none;'
        );
        $details->appendChild($summary);

        $first->parentNode->replaceChild($details, $first);
        $details->appendChild($first);
        foreach (array_slice($nodes, 1) as $node) {
            $details->appendChild($node);
        }
    }

    /**
     * Похож ли текст на реплай-хедер Outlook/корп-систем: «From:» + минимум
     * два из полей Sent/To/Subject/Date (или их русские аналоги), либо
     * разделитель «--- Original Message ---». Порог из двух полей защищает
     * от ложного срабатывания на «От:» внутри обычного текста письма.
     */
    private function looksLikeReplyHeader(string $text): bool
    {
        if (preg_match('/^-{2,}\s*(Original Message|Исходное сообщение|Forwarded message|Пересылаемое сообщение|Перенаправленное сообщение)/iu', $text)) {
            return true;
        }
        if (preg_match('/(^|\s)(From|От)\s*:/iu', $text) !== 1) {
            return false;
        }
        $fields = 0;
        foreach (['/(Sent|Отправлено)\s*:/iu', '/(To|Кому)\s*:/iu', '/(Subject|Тема)\s*:/iu', '/(Date|Дата)\s*:/iu'] as $p) {
            if (preg_match($p, $text) === 1) {
                $fields++;
            }
        }

        return $fields >= 2;
    }

    /** Есть ли перед нодой (на её уровне) содержательный контент письма. */
    private function hasMeaningfulPrecedingSibling(\DOMNode $node): bool
    {
        for ($p = $node->previousSibling; $p !== null; $p = $p->previousSibling) {
            if ($p->nodeType === XML_TEXT_NODE && trim($p->textContent) !== '') {
                return true;
            }
            if ($p->nodeType === XML_ELEMENT_NODE) {
                if (in_array(strtolower($p->nodeName), ['br', 'hr'], true)) {
                    continue;
                }
                if (trim($p->textContent) !== '') {
                    return true;
                }
                if ($p instanceof \DOMElement && $p->getElementsByTagName('img')->length > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Эвристика: похож ли DOM-нода на attribution-строку перед цитатой?
     *
     * Yandex web UI / Outlook / Apple Mail RU кладут перед blockquote
     * блок с «Кому:», «Тема:», «От:», «DD.MM.YYYY, ..., NAME <email>
     * написал(а):» или « ---- Original message ----». Эти строки логически
     * принадлежат цитате, но физически в HTML лежат отдельно.
     *
     * Мы их детектим по тексту: если первая непустая строка содержит
     * один из якорей — считаем attribution.
     */
    private function looksLikeQuoteAttribution(\DOMNode $node): bool
    {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return false;
        }
        $tag = strtolower($node->nodeName);
        if (! in_array($tag, ['div', 'p', 'span', 'blockquote'], true)) {
            return false;
        }
        $text = trim($node->textContent);
        if ($text === '' || mb_strlen($text) > 600) {
            // Слишком длинный — это уже не header, а body соседнего письма.
            return false;
        }
        // Якоря — RU/EN attribution patterns.
        $patterns = [
            '/^\s*Кому\s*:/iu',
            '/^\s*Тема\s*:/iu',
            '/^\s*От\s*:/iu',
            '/^\s*Дата\s*:/iu',
            '/^\s*To\s*:/i',
            '/^\s*From\s*:/i',
            '/^\s*Subject\s*:/i',
            '/^\s*Date\s*:/i',
            '/-{3,}\s*(Original message|Перенаправленное сообщение|Forwarded message|Пересылаемое сообщение)/iu',
            // «14.05.2026, 19:43, "Имя" <email> написал(а):»
            '/\d{1,2}[\.\/]\d{1,2}[\.\/]\d{2,4}.*(написал|wrote)/iu',
            // «14 мая 2026 г., в 19:43, Имя <email> написал(а):»
            '/\d{1,2}\s+\p{L}+\s+\d{4}.*(написал|wrote)/iu',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Разбить plain-text письмо на [видимая часть, свёрнутая цитата|null] для
     * треда: цитата = с первой строки-маркера («>»-префикс, реплай-хедер
     * From:/От:, «--- Original Message ---», «…пишет:/wrote:») до конца.
     * Если до маркера нет собственного текста (bottom-posting / форвард
     * целиком) — не сворачиваем.
     *
     * @return array{0: string, 1: ?string}
     */
    public function splitPlainQuote(?string $plain): array
    {
        $plain = (string) $plain;
        if (trim($plain) === '') {
            return [$plain, null];
        }

        $patterns = [
            '/^[ \t]*>/m',
            '/^[ \t]*-{2,}\s*(Original Message|Исходное сообщение|Forwarded message|Пересылаемое сообщение|Перенаправленное сообщение)/miu',
            // Outlook-хедер: «От: …» и в пределах пары строк «Отправлено:/Кому:/…».
            '/^[ \t]*(From|От)\s*:[^\n]+\n(?:[^\n]*\n){0,3}?[ \t]*(Sent|Отправлено|To|Кому|Date|Дата|Subject|Тема)\s*:/miu',
            // «01.06.2026 12:09, Имя пишет:» / «… wrote:»
            '/^[^\n]{0,160}(написал\(а\)|написала?|пишет|wrote)\s*:\s*$/miu',
        ];

        $idx = null;
        foreach ($patterns as $p) {
            if (preg_match($p, $plain, $m, PREG_OFFSET_CAPTURE) === 1) {
                $pos = (int) $m[0][1];
                $idx = $idx === null ? $pos : min($idx, $pos);
            }
        }
        if ($idx === null) {
            return [$plain, null];
        }

        $visible = rtrim(substr($plain, 0, $idx));
        if (trim($visible) === '') {
            return [$plain, null];
        }

        return [$visible, substr($plain, $idx)];
    }

    /**
     * Совместимость: legacy-вызов из blade `$this->bodyHtml()` для trigger-email.
     */
    public function bodyHtml(): ?string
    {
        $email = $this->request->emailMessage;

        return $email ? $this->bodyHtmlFor($email) : null;
    }

    /* ---------------- Priority 1 — ручные действия с позициями ---------------- */

    public function toggleDeletedItems(): void
    {
        $this->showDeletedItems = ! $this->showDeletedItems;
        $this->reloadRequest();
    }

    public function toggleStickyItems(): void
    {
        $this->includeStickyItems = ! $this->includeStickyItems;
    }

    /**
     * Переключить порядок писем в «Переписке» (старые/новые сверху) и сохранить
     * выбор в настройках пользователя — он применится во всех заявках.
     */
    /* -------------------- Номер заявки/КП из 1С (шапка) -------------------- */

    /** Поле ввода номера 1С в шапке. */
    public string $oneCNumberInput = '';

    /** Режим правки уже установленного номера (только РОП/директор/админ). */
    public bool $editingOneCNumber = false;

    public function startEditOneCNumber(): void
    {
        if (! $this->canChangeOneCNumber()) {
            $this->dispatch('toast', message: 'Менять номер 1С может только РОП или директор.', type: 'error');

            return;
        }
        $this->oneCNumberInput = (string) $this->request->onec_number;
        $this->editingOneCNumber = true;
    }

    public function cancelEditOneCNumber(): void
    {
        $this->editingOneCNumber = false;
        $this->oneCNumberInput = '';
    }

    /**
     * Сохранить номер заявки/КП из 1С. Первичный ввод — менеджер заявки
     * (owner/acting/privileged); ИЗМЕНЕНИЕ уже установленного номера — только
     * РОП/директор/админ (правило заказчика: менеджер ошибся — исправляет РОП).
     * Аудит — request_state_changes (event onec_number_set/changed).
     */
    public function saveOneCNumber(): void
    {
        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $value = trim($this->oneCNumberInput);
        if ($value === '' || mb_strlen($value) > 64) {
            $this->dispatch('toast', message: 'Укажите номер из 1С (до 64 символов).', type: 'error');

            return;
        }

        $old = trim((string) $this->request->onec_number);
        if ($old === $value) {
            $this->editingOneCNumber = false;

            return;
        }

        if ($old !== '') {
            if (! $this->canChangeOneCNumber()) {
                $this->dispatch('toast', message: 'Номер 1С уже установлен — изменить может только РОП или директор.', type: 'error');

                return;
            }
        } else {
            if ($user->hasRole(\App\Enums\Role::Secretary->value)
                || ! $this->request->isAccessibleBy($user)) {
                $this->dispatch('toast', message: 'Нет прав указать номер 1С для этой заявки.', type: 'error');

                return;
            }
        }

        $this->request->forceFill(['onec_number' => $value])->save();

        try {
            \App\Models\RequestStateChange::create([
                'request_id' => $this->request->id,
                'from_status' => $this->request->status->value,
                'to_status' => $this->request->status->value,
                'by_user_id' => $user->id,
                'event' => $old === '' ? 'onec_number_set' : 'onec_number_changed',
                'comment' => $old === ''
                    ? sprintf('Указан номер 1С: %s.', $value)
                    : sprintf('Номер 1С изменён: %s → %s.', $old, $value),
                'payload' => ['old' => $old ?: null, 'new' => $value],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Detail: onec number audit failed (non-fatal)', [
                'request_id' => $this->request->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->editingOneCNumber = false;
        $this->oneCNumberInput = '';
        $this->reloadRequest();
        $this->dispatch('toast', message: "Номер 1С сохранён: {$value}.", type: 'success');
    }

    /** Менять УЖЕ установленный номер 1С — только РОП/директор/админ. */
    public function canChangeOneCNumber(): bool
    {
        return auth()->user()?->hasAnyRole([
            \App\Enums\Role::HeadOfSales->value,
            \App\Enums\Role::Director->value,
            \App\Enums\Role::Admin->value,
        ]) ?? false;
    }

    /**
     * Подсказка для поля: номер последнего распознанного исходящего КП/счёта
     * (детектор уже видел документ 1С в письмах — вероятно, это и есть номер).
     */
    #[Computed]
    public function oneCNumberSuggestion(): ?string
    {
        if ($this->request->onec_number !== null) {
            return null;
        }

        return \App\Models\OutboundQuote::query()
            ->where('request_id', $this->request->id)
            ->whereNotNull('document_number')
            ->orderByDesc('id')
            ->value('document_number');
    }

    public function applyOneCNumberSuggestion(): void
    {
        $suggestion = $this->oneCNumberSuggestion();
        if ($suggestion !== null) {
            $this->oneCNumberInput = (string) $suggestion;
            $this->saveOneCNumber();
        }
    }

    public function toggleThreadSort(): void
    {
        $this->threadSort = $this->threadSort === 'asc' ? 'desc' : 'asc';
        auth()->user()?->forceFill(['thread_sort_order' => $this->threadSort])->save();
    }

    /**
     * Foundation §6.2 комбо-режим: раскрыть/свернуть карточку позиции.
     * Свёрнутая = compact-row; раскрытая = full card со слотами,
     * quick-chips, textarea произвольного вопроса, history.
     */
    public function togglePositionExpand(int $itemId): void
    {
        if (isset($this->expandedPositions[$itemId])) {
            unset($this->expandedPositions[$itemId]);
        } else {
            $this->expandedPositions[$itemId] = true;
        }
    }

    /**
     * Phase E.2: «Скрыть» AI-баннер до перезагрузки страницы.
     */
    public function hideAiBanner(): void
    {
        $this->aiBannerHidden = true;
    }

    /**
     * Free-text вопрос из expanded-карточки — добавляет произвольный
     * текст в clarification draft через тот же payload, что и
     * slot/quick-chip кнопки. Полная валидация в ClarificationPanel.
     */
    public function addFreeTextQuestion(int $itemId, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        $this->dispatch(
            'clarification-add-slot-question',
            itemId: $itemId,
            slotKey: 'free',
            slotLabel: null,
            template: $text,
            itemName: null,
        );
    }

    /**
     * Sticky-связанные заявки (forward + reverse) — для опции
     * «Показать позиции sticky-заявок» в табе «Позиции».
     *
     * Forward: эта заявка ссылается на других через sticky['links'].
     * Reverse: другие заявки ссылаются на эту через
     * `request_assignments.reason LIKE '%auto_sticky:%"linked":[..., this_id, ...]%'`.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Request>
     */
    #[Computed]
    public function relatedStickyRequests()
    {
        $forward = $this->sticky['links'];
        $forwardIds = $forward->pluck('id')->all();

        // Reverse: смотрим в request_assignments кто упоминает текущий ID
        // в auto_sticky:{"linked":[...]}. JSON-as-string в reason — простое
        // ILIKE-сравнение по подстроке "%, $id]" / "[$id," / "[$id]".
        $thisId = $this->request->id;
        $reverseIds = \App\Models\RequestAssignment::query()
            ->where('reason', 'like', 'auto_sticky:%')
            ->where(function ($q) use ($thisId) {
                $q->where('reason', 'like', '%"linked":[' . $thisId . ']%')
                    ->orWhere('reason', 'like', '%"linked":[' . $thisId . ',%')
                    ->orWhere('reason', 'like', '%,' . $thisId . ',%')
                    ->orWhere('reason', 'like', '%,' . $thisId . ']%');
            })
            ->pluck('request_id')
            ->unique()
            ->reject(fn ($id) => $id === $thisId)
            ->all();

        $allIds = array_values(array_unique(array_merge($forwardIds, $reverseIds)));
        if (empty($allIds)) {
            return collect();
        }

        return Request::query()
            ->whereIn('id', $allIds)
            ->where('id', '!=', $thisId)
            ->with([
                'items' => fn ($q) => $q->where('is_active', true)->orderBy('position'),
                'items.brand:id,name',
                'items.kbCategory:id,slug,name',
                'items.catalogItem:id,sku,name,brand,brand_article,price,stock_available',
                'assignedUser:id,name',
            ])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Pending AI-suggestion'ы DocumentDetector (Foundation §7).
     * Если есть — в action-panel рендерится плашка «AI: …» с apply/dismiss.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AiDecision>
     */
    #[Computed]
    public function pendingAiDecisions()
    {
        return AiDecision::query()
            ->where('request_id', $this->request->id)
            ->where('status', \App\Enums\AiDecisionStatus::Suggested->value)
            ->with(['emailMessage:id,subject,from_email,sent_at,direction'])
            ->orderByDesc('id')
            ->get();
    }

    // supplierInquiries computed убран — список «кому отправлено» теперь в
    // табе «Поставщики» (Requests\SupplierDispatchPanel::sentInquiries).

    /**
     * id позиций, по которым отправлен запрос поставщику и ждём ответ
     * (supplier_inquiry_items.status=pending). Для пометки «ждём поставщика»
     * в табе «Позиции».
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    #[Computed]
    public function requestedItemIds()
    {
        return \App\Models\SupplierInquiryItem::query()
            ->whereHas('inquiry', fn ($q) => $q->where('related_request_id', $this->request->id))
            ->where('status', 'pending')
            ->pluck('request_item_id')
            ->filter()
            ->unique()
            ->values();
    }

    public function applyAiDecision(int $decisionId, AiDecisionService $svc): void
    {
        $decision = AiDecision::query()
            ->where('request_id', $this->request->id)
            ->whereKey($decisionId)
            ->first();
        if (! $decision) {
            return;
        }
        $svc->apply($decision, auth()->user());
        $this->reloadRequest();
        session()->flash('status', sprintf(
            'AI-предложение применено: %s.',
            $decision->detector_type->label(),
        ));
    }

    public function dismissAiDecision(int $decisionId, AiDecisionService $svc): void
    {
        $decision = AiDecision::query()
            ->where('request_id', $this->request->id)
            ->whereKey($decisionId)
            ->first();
        if (! $decision) {
            return;
        }
        $svc->dismiss($decision, auth()->user());
        $this->reloadRequest();
    }

    public function editItemField(int $itemId, string $field, mixed $value, RequestItemEditor $editor): void
    {
        $item = $this->loadItemOrFail($itemId);
        $editor->editFields($item, [$field => $value], auth()->user());
        $this->reloadRequest();
    }

    public function softDeleteItem(int $itemId, RequestItemEditor $editor): void
    {
        $item = $this->loadItemOrFail($itemId);
        $editor->softDelete($item, auth()->user());
        $this->reloadRequest();
    }

    public function restoreItem(int $itemId, RequestItemEditor $editor): void
    {
        $item = $this->loadItemOrFail($itemId, includeDeleted: true);
        $editor->restore($item, auth()->user());
        $this->reloadRequest();
    }

    public function unbindItemCatalog(int $itemId, RequestItemEditor $editor): void
    {
        $item = $this->loadItemOrFail($itemId);
        $editor->unbindCatalog($item, auth()->user());
        $this->reloadRequest();
    }

    public function refreshItemCatalog(int $itemId, RequestItemEditor $editor): void
    {
        $item = $this->loadItemOrFail($itemId);
        $result = $editor->refreshFromCatalog($item, auth()->user());
        $this->reloadRequest();
        session()->flash(
            'status',
            $result->catalog_item_id
                ? 'Позиция #' . $result->position . ' заново сматчена с каталогом.'
                : 'Не нашлось каталожного аналога для позиции #' . $result->position . '.',
        );
    }

    public function markItemCatalogNotFound(int $itemId, RequestItemEditor $editor): void
    {
        $item = $this->loadItemOrFail($itemId);
        $editor->markCatalogNotFound($item, auth()->user());
        $this->reloadRequest();
    }

    /**
     * Слить позицию-источник в существующую (operator-driven clarification).
     * Когда LLM-проход не распознал голый артикул в reply как уточнение
     * существующей позиции — оператор вручную сматчит через
     * «⋮ → 🔗 Это уточнение позиции №X».
     */
    public function mergeItemInto(int $sourceId, int $targetId, RequestItemEditor $editor): void
    {
        $source = $this->loadItemOrFail($sourceId);
        $target = $this->loadItemOrFail($targetId);
        try {
            $editor->mergeIntoExisting($source, $target, auth()->user());
            session()->flash('status', sprintf(
                'Позиция #%d слита в #%d.',
                $source->position,
                $target->position,
            ));
        } catch (\DomainException $e) {
            $this->addError('status', $e->getMessage());
            return;
        }
        $this->reloadRequest();
    }

    /**
     * Bulk re-match всех позиций заявки. Сбрасывает catalog_item_id (кроме
     * internal_catalog_not_found) и прогоняет matchOrResolve. Используется
     * после редактирования названий — даёт «применить изменения».
     */
    public function rematchAllItems(RequestItemEditor $editor): void
    {
        // C-step (vector + LLM) per item ≈ 5-10 сек. На 10 позициях — до 100 сек.
        // PHP-FPM default 30s → поднимаем таймаут.
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');

        $stats = $editor->rematchAll($this->request->fresh(), auth()->user());
        $this->reloadRequest();
        session()->flash(
            'status',
            sprintf(
                'Пересмотрено %d · сматчено %d · без аналога %d · пропущено %d',
                $stats['checked'],
                $stats['matched'],
                $stats['unchanged'],
                $stats['skipped'],
            ),
        );
    }

    #[On('item-relinked')]
    #[On('item-edited')]
    #[On('items-changed')]
    #[On('quote-item-rematched')]
    #[On('item-photo-rebound')]
    public function handleItemChangedEvent(): void
    {
        $this->reloadRequest();
    }

    /**
     * Foundation §6.2 Phase C — применить enrichment suggestion
     * (LLM извлёк из ответа клиента артикул/бренд/qty) к позиции.
     */
    public function applyEnrichmentSuggestion(int $itemId, string $suggestionId, RequestItemEditor $editor): void
    {
        $item = $this->loadItemOrFail($itemId);
        $editor->applyEnrichmentSuggestion($item, $suggestionId, auth()->user());
        $this->reloadRequest();
        session()->flash('status', 'Позиция обогащена данными из ответа клиента.');
    }

    public function dismissEnrichmentSuggestion(int $itemId, string $suggestionId, RequestItemEditor $editor): void
    {
        $item = $this->loadItemOrFail($itemId);
        $editor->dismissEnrichmentSuggestion($item, $suggestionId, auth()->user());
        $this->reloadRequest();
    }

    /**
     * Foundation §6.2 Phase C+ — менеджер выбрал перенаправить enrichment
     * в конкретный слот (LLM ошибся / клиент имел в виду другое поле).
     *
     * targetSlot: 'brand' | 'article' | 'qty' | 'kb:<slug>'
     */
    public function applyEnrichmentToSlot(int $itemId, string $suggestionId, string $targetSlot, RequestItemEditor $editor): void
    {
        $item = $this->loadItemOrFail($itemId);
        $editor->applyEnrichmentSuggestionToSlot($item, $suggestionId, $targetSlot, auth()->user());
        $this->reloadRequest();
        session()->flash('status', 'Значение перенесено в выбранный слот.');
    }

    /**
     * Foundation §6.2 Phase E — bulk apply: применить ВСЕ pending
     * enrichment suggestions всех активных позиций заявки одним
     * действием. Используется кнопкой «Применить все (N)» в топ-секции.
     */
    public function applyAllEnrichments(RequestItemEditor $editor): void
    {
        $applied = 0;
        $user = auth()->user();
        foreach ($this->request->items as $item) {
            if (! $item->is_active) {
                continue;
            }
            $sugs = is_array($item->quality_assessment_payload['enrichment_suggestions'] ?? null)
                ? $item->quality_assessment_payload['enrichment_suggestions'] : [];
            foreach ($sugs as $sugg) {
                if (is_array($sugg) && ($sugg['status'] ?? 'pending') === 'pending'
                    && ! empty($sugg['id'])) {
                    try {
                        $editor->applyEnrichmentSuggestion($item->fresh(), (string) $sugg['id'], $user);
                        $applied++;
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('applyAllEnrichments: skip', [
                            'item_id' => $item->id,
                            'sugg_id' => $sugg['id'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
        $this->reloadRequest();
        session()->flash('status', $applied > 0
            ? sprintf('Применено %d предложен%s.', $applied, $applied === 1 ? 'ие' : ($applied < 5 ? 'ия' : 'ий'))
            : 'Не было pending-предложений.');
    }

    /**
     * Foundation §6.2 Phase E — bulk dismiss: отклонить ВСЕ pending
     * enrichment suggestions заявки. Кнопка «Отклонить все».
     */
    public function dismissAllEnrichments(RequestItemEditor $editor): void
    {
        $dismissed = 0;
        $user = auth()->user();
        foreach ($this->request->items as $item) {
            if (! $item->is_active) {
                continue;
            }
            $sugs = is_array($item->quality_assessment_payload['enrichment_suggestions'] ?? null)
                ? $item->quality_assessment_payload['enrichment_suggestions'] : [];
            foreach ($sugs as $sugg) {
                if (is_array($sugg) && ($sugg['status'] ?? 'pending') === 'pending'
                    && ! empty($sugg['id'])) {
                    try {
                        $editor->dismissEnrichmentSuggestion($item->fresh(), (string) $sugg['id'], $user);
                        $dismissed++;
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('dismissAllEnrichments: skip', [
                            'item_id' => $item->id,
                            'sugg_id' => $sugg['id'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
        $this->reloadRequest();
        if ($dismissed > 0) {
            session()->flash('status', sprintf('Отклонено %d предложений.', $dismissed));
        }
    }

    /**
     * Foundation §6.2 Phase E.2 — комбо-действие AI-баннера:
     * применить ВСЕ pending enrichments + перевести заявку в InProgress.
     * Если она в AwaitingClientClarification — переход допустим.
     */
    public function applyAllAndProgress(RequestItemEditor $editor, RequestStateService $state): void
    {
        $this->applyAllEnrichments($editor);
        // Перевод статуса — только если сейчас в AwaitingClientClarification.
        $fresh = $this->request->fresh();
        if ($fresh && $fresh->status === RequestStatus::AwaitingClientClarification) {
            try {
                $state->transitionTo($fresh, RequestStatus::InProgress, auth()->user());
                $this->reloadRequest();
                session()->flash('status', 'Уточнения применены, заявка возвращена в работу.');
            } catch (\DomainException $e) {
                $this->addError('status', 'Не удалось перевести статус: ' . $e->getMessage());
            }
        }
    }

    /**
     * Foundation §6.2 Phase E.2 — откатить applied enrichment suggestion.
     * Сбрасывает значение в слоте обратно (старое из audit log) либо в null.
     * status: applied → reverted.
     */
    public function rollbackEnrichmentSuggestion(int $itemId, string $suggestionId, RequestItemEditor $editor): void
    {
        $item = $this->loadItemOrFail($itemId);
        $editor->rollbackEnrichmentSuggestion($item, $suggestionId, auth()->user());
        $this->reloadRequest();
        session()->flash('status', 'Применение откатано.');
    }

    /**
     * Foundation §6.2: ClarificationPanel сформировал черновик письма
     * и диспатчит этот event. Мы должны переключить таб на «Переписка»
     * (там зарегистрирован ComposeForm) и попросить его открыть draft.
     * ComposeForm::openDraft слушает 'open-draft' — он поймает после
     * того как Livewire patch'нет DOM (tab='thread' → ComposeForm
     * рендерится → потом исполняется dispatch на client).
     */
    #[On('clarification-letter-ready')]
    public function openClarificationDraft(int $draftId): void
    {
        $this->tab = 'thread';
        $this->dispatch('open-draft', draftId: $draftId, requestId: $this->request->id);
    }

    /**
     * Phase 4: «📨 Отправить КП клиенту» — Editor сгенерировал PDF
     * + создал draft с прикреплённым КП. Переключаем таб на «Переписка»
     * (где зарегистрирован ComposeForm) и просим его открыть draft —
     * менеджер видит готовое письмо, может править recipients/body
     * и отправить. После send'а ComposeForm::applyPostSendHooks подхватит
     * marker `quotation_sent` и переведёт заявку в Quoted.
     */
    #[On('quotation-send-ready')]
    public function openQuotationDraft(int $draftId): void
    {
        $this->tab = 'thread';
        $this->dispatch('open-draft', draftId: $draftId, requestId: $this->request->id);
    }

    /* ---------------- Phase 4 — inline Invoices section --------------------- */

    /**
     * Перезапустить парсер позиций для триггерного email заявки.
     *
     * Кейс: парсер в момент IMAP-sync упал на transient OpenAI ошибке
     * (429/503/timeout), пометил parser_finished_at, items остались = 0.
     * После того как OpenAI ожил, никто автоматически не делает retry.
     * РОП/админ/owner жмёт кнопку → ParseRequestItemsJob(force=true,
     * reset=true) — Vision повторно пробует фото+текст.
     *
     * reset=true стирает existing items перед persist'ом — на случай если
     * прошлый прогон оставил «склеенный» мусор.
     */
    public function reparseItems(): void
    {
        $user = auth()->user();
        $privileged = $user?->hasAnyRole([
            Role::HeadOfSales->value,
            Role::Director->value,
            Role::Admin->value,
        ]) ?? false;
        if (! $user || (! $privileged && ! $this->request->isAccessibleBy($user))) {
            $this->dispatch('toast', message: 'Нет прав.', type: 'error');
            return;
        }
        $emailId = $this->request->email_message_id;
        if (! $emailId) {
            $this->dispatch('toast', message: 'У заявки нет триггерного письма.', type: 'error');
            return;
        }

        try {
            // Снять отметку «парсер закончил» и поставить «reparse запущен» —
            // isParsingInFlight() работает по этим двум меткам совместно
            // (см. Request::isParsingInFlight). Без reparse_dispatched_at
            // флаг in-flight оставался false на non-Pending статусах
            // (quoted/in_progress…), wire:poll не запускался, и UI
            // выглядел как «кнопка не сработала».
            $meta = is_array($this->request->parsing_meta) ? $this->request->parsing_meta : [];
            unset($meta['parser_finished_at']);
            $meta['reparse_dispatched_at'] = now()->toIso8601String();
            $this->request->forceFill(['parsing_meta' => $meta])->save();

            \App\Jobs\Mail\ParseRequestItemsJob::dispatch($emailId, force: true, reset: true);

            // Перезагружаем модель через стандартный паттерн (не unset — он
            // ломает Livewire public-property и при перерисовке blade падает
            // в 500, хотя dispatch parsler уже улетел в очередь).
            $this->reloadRequest();

            $this->dispatch('toast', message: 'Парсер перезапущен. Карточка обновится автоматически.', type: 'success');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Detail::reparseItems failed', [
                'request_id' => $this->request->id ?? null,
                'email_message_id' => $emailId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('toast', message: 'Не удалось перезапустить: ' . $e->getMessage(), type: 'error');
        }
    }

    /**
     * Счета заявки (inline-секция в action-panel). Эта computed-prop
     * — не для отдельного pagination, а для компактного списка из 3-5
     * последних invoice'ов. Полный листинг — на /dashboard/invoices.
     */
    #[\Livewire\Attributes\Computed]
    public function invoicesForRequest()
    {
        return \App\Models\Invoice::query()
            ->where('request_id', $this->request->id)
            ->with('createdByUser:id,name')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    /**
     * Отметить счёт оплаченным прямо из карточки заявки.
     * Permission: owner / acting (delegation) / privileged (head_of_sales,
     * director, admin). Inline-проверка через Request::isAccessibleBy.
     */
    public function markInvoicePaid(int $invoiceId, \App\Services\Invoices\InvoiceService $service): void
    {
        $user = auth()->user();
        $privileged = $user?->hasAnyRole([
            Role::HeadOfSales->value,
            Role::Director->value,
            Role::Admin->value,
        ]) ?? false;
        if (! $user || (! $privileged && ! $this->request->isAccessibleBy($user))) {
            $this->dispatch('toast', message: 'Нет прав.', type: 'error');
            return;
        }
        $invoice = \App\Models\Invoice::where('request_id', $this->request->id)
            ->whereKey($invoiceId)
            ->first();
        if (! $invoice) {
            return;
        }
        try {
            $service->markPaid($invoice, auth()->user());
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Ошибка: ' . $e->getMessage(), type: 'error');
            return;
        }
        $this->dispatch('toast', message: "Счёт №{$invoice->invoice_number} оплачен.", type: 'success');
        $this->dispatch('request-state-changed');
        unset($this->invoicesForRequest);
        $this->request->refresh();
    }

    /**
     * Аннулировать счёт. Подтверждение через wire:confirm + prompt для
     * reason'а (передаётся как параметр от UI).
     */
    public function cancelInvoice(int $invoiceId, string $reason, \App\Services\Invoices\InvoiceService $service): void
    {
        $user = auth()->user();
        $privileged = $user?->hasAnyRole([
            Role::HeadOfSales->value,
            Role::Director->value,
            Role::Admin->value,
        ]) ?? false;
        if (! $user || (! $privileged && ! $this->request->isAccessibleBy($user))) {
            $this->dispatch('toast', message: 'Нет прав.', type: 'error');
            return;
        }
        $reason = trim($reason);
        if ($reason === '') {
            $this->dispatch('toast', message: 'Укажите причину аннулирования.', type: 'error');
            return;
        }
        $invoice = \App\Models\Invoice::where('request_id', $this->request->id)
            ->whereKey($invoiceId)
            ->first();
        if (! $invoice) {
            return;
        }
        try {
            $service->cancel($invoice, $reason, auth()->user());
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Ошибка: ' . $e->getMessage(), type: 'error');
            return;
        }
        $this->dispatch('toast', message: "Счёт №{$invoice->invoice_number} аннулирован.", type: 'success');
        $this->dispatch('request-state-changed');
        unset($this->invoicesForRequest);
        $this->request->refresh();
    }

    /* ---------------- Phase 1.10 — state-machine transitions ---------------- */

    /**
     * Простой переход в статус без модалок: in_progress, quoted, under_review,
     * awaiting_invoice, invoiced, paid, closed_won, awaiting_client_clarification.
     */
    public function transitionStatus(string $to, RequestStateService $service): void
    {
        $target = RequestStatus::tryFrom($to);
        if ($target === null) {
            $this->addError('status', 'Неизвестный статус: ' . $to);
            return;
        }
        if ($target === RequestStatus::ClosedLost) {
            $this->addError('status', 'closed_lost — через диалог с reason.');
            return;
        }
        if ($target === RequestStatus::Paused) {
            $this->addError('status', 'paused — через диалог с датой.');
            return;
        }

        try {
            $req = $this->request->fresh();
            $service->transitionTo($req, $target, auth()->user());
            $this->reloadRequest();
            session()->flash('status', 'Статус обновлён: ' . $target->label());
        } catch (\DomainException $e) {
            $this->addError('status', $e->getMessage());
        }
    }

    // markAsSupplierInquiry удалён: пометка «запрос поставщику» теперь идёт
    // через CloseLostDialog (причина «Переписка с поставщиком» + опц. стоп-лист).

    /**
     * Поставить / снять ручной флаг attention. Менеджер — на свою или
     * делегированную заявку; РОП/директор — на любую. Manual флаг sticky:
     * не затирается recompute/onClientReplied/onManagerOpened.
     */
    public function toggleManualAttention(
        \App\Services\Request\AttentionService $attention,
        \App\Services\Request\RequestActivityService $activity,
    ): void {
        $user = auth()->user();
        if ($user === null) {
            abort(403);
        }
        $privileged = $user->hasAnyRole([
            Role::HeadOfSales->value,
            Role::Director->value,
            Role::Admin->value,
        ]);
        if ($user->hasRole(Role::Secretary->value)) {
            abort(403, 'Секретарь только просматривает заявки.');
        }
        if (! $privileged && ! $this->request->isAccessibleBy($user)) {
            abort(403, 'Менять флаг внимания может только assigned-менеджер, acting или РОП.');
        }

        $req = $this->request->fresh();
        if ($req === null) {
            return;
        }

        // Eloquent cast attention_reason → AttentionReason enum; сравниваем
        // через ->value на случай если каст ещё не сработал (forceFill).
        $manualValue = \App\Enums\AttentionReason::Manual->value;
        $currentReason = $req->attention_reason instanceof \App\Enums\AttentionReason
            ? $req->attention_reason->value
            : $req->attention_reason;
        $isSet = $currentReason === $manualValue;

        try {
            if ($isSet) {
                $attention->clearManual($req);
                $activity->touch($req, \App\Enums\RequestActivityType::ManualFlagCleared);
                session()->flash('status', 'Ручной флаг внимания снят.');
            } else {
                $attention->setManual($req, $user);
                $activity->touch($req, \App\Enums\RequestActivityType::ManualFlagSet);
                session()->flash('status', 'Заявка помечена как «требует внимания».');
            }
        } catch (\Throwable $e) {
            $this->addError('status', 'Не удалось переключить флаг: ' . $e->getMessage());

            return;
        }

        $this->reloadRequest();
    }

    /**
     * Phase reply-suggestion: подтвердить pending-позицию от парсера.
     * Делает is_active=true, suggestion_status='applied'. Audit в Activity.
     */
    public function applyPositionSuggestion(int $itemId): void
    {
        $user = auth()->user();
        if ($user === null) {
            abort(403);
        }
        if (! $this->request->isAccessibleBy($user) && ! $user->hasAnyRole([Role::HeadOfSales->value, Role::Director->value, Role::Admin->value])) {
            abort(403);
        }

        $item = \App\Models\RequestItem::query()
            ->where('request_id', $this->request->id)
            ->whereKey($itemId)
            ->where('suggestion_status', 'pending')
            ->first();
        if (! $item) {
            return;
        }

        $item->forceFill([
            'is_active' => true,
            'suggestion_status' => 'applied',
        ])->save();

        \App\Models\RequestStateChange::create([
            'request_id' => $this->request->id,
            'from_status' => $this->request->status->value,
            'to_status' => $this->request->status->value,
            'by_user_id' => $user->id,
            'event' => 'suggestion_applied',
            'comment' => sprintf('Подтверждена pending-позиция #%d %s', $item->position, $item->parsed_name),
            'payload' => [
                'item_id' => $item->id,
                'article' => $item->parsed_article,
                'confidence' => $item->suggestion_confidence,
                'source_email_id' => $item->suggestion_source_email_id,
            ],
        ]);

        $this->reloadRequest();
        session()->flash('status', 'Позиция подтверждена и добавлена в заявку.');
    }

    /**
     * Phase reply-suggestion: отклонить pending-позицию.
     * is_active=false (уже), suggestion_status='rejected'. Audit.
     */
    public function rejectPositionSuggestion(int $itemId): void
    {
        $user = auth()->user();
        if ($user === null) {
            abort(403);
        }
        if (! $this->request->isAccessibleBy($user) && ! $user->hasAnyRole([Role::HeadOfSales->value, Role::Director->value, Role::Admin->value])) {
            abort(403);
        }

        $item = \App\Models\RequestItem::query()
            ->where('request_id', $this->request->id)
            ->whereKey($itemId)
            ->where('suggestion_status', 'pending')
            ->first();
        if (! $item) {
            return;
        }

        $item->forceFill([
            'is_active' => false,
            'suggestion_status' => 'rejected',
        ])->save();

        \App\Models\RequestStateChange::create([
            'request_id' => $this->request->id,
            'from_status' => $this->request->status->value,
            'to_status' => $this->request->status->value,
            'by_user_id' => $user->id,
            'event' => 'suggestion_rejected',
            'comment' => sprintf('Отклонена pending-позиция #%d %s', $item->position, $item->parsed_name),
            'payload' => [
                'item_id' => $item->id,
                'article' => $item->parsed_article,
                'confidence' => $item->suggestion_confidence,
                'source_email_id' => $item->suggestion_source_email_id,
            ],
        ]);

        $this->reloadRequest();
        session()->flash('status', 'Предложенная позиция отклонена.');
    }

    /**
     * Снять с паузы вручную (не дожидаясь cron).
     */
    public function resumeFromPause(RequestPauseService $service): void
    {
        try {
            $req = $this->request->fresh();
            $service->resume($req, auth()->user(), event: 'manual');
            $this->reloadRequest();
            session()->flash('status', 'Заявка снята с паузы.');
        } catch (\Throwable $e) {
            $this->addError('status', 'Не удалось снять с паузы: ' . $e->getMessage());
        }
    }

    #[On('request-state-changed')]
    public function handleRequestStateChanged(): void
    {
        $this->reloadRequest();
    }

    /**
     * Загрузить RequestItem с проверкой что он действительно от этой заявки.
     * Защищает от попытки изменить чужую позицию через подменённый Livewire wire:call.
     */
    private function loadItemOrFail(int $itemId, bool $includeDeleted = false): RequestItem
    {
        $query = RequestItem::query()
            ->with(['request:id,assigned_user_id,internal_code'])
            ->where('request_id', $this->request->id)
            ->whereKey($itemId);
        if (! $includeDeleted) {
            $query->where('is_active', true);
        }
        return $query->firstOrFail();
    }

    public function render()
    {
        // Livewire при ре-гидратации $this->request между запросами теряет
        // withCount-агрегаты (photos_count — это атрибут модели, не relation),
        // из-за чего бейдж «сколько фото» в списке позиций мог не появляться.
        // Пересчитываем дёшево одним запросом прямо перед рендером.
        if ($this->request->relationLoaded('items') && $this->request->items->isNotEmpty()) {
            $this->request->items->loadCount('photos');
        }

        return view('livewire.requests.detail');
    }
}
