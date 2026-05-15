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
    public const TABS = ['overview', 'thread', 'items', 'suppliers', 'activity', 'files', 'related'];

    public Request $request;

    /** @var Collection<int, EmailMessage> */
    public Collection $thread;

    #[Url(as: 'tab')]
    public string $tab = 'overview';

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
        $isPrivileged = $user?->hasAnyRole([
            Role::HeadOfSales->value,
            Role::Director->value,
            Role::Secretary->value,
        ]);

        if (! $isPrivileged && $request->assigned_user_id !== $user?->id) {
            abort(403, 'Эта заявка назначена другому менеджеру.');
        }

        $this->request = $request->load([
            'assignedUser:id,name,email',
            'items' => fn ($q) => $this->applyItemsFilter($q)->orderBy('position'),
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
        ]);

        // Полный тред: все письма прицепленные к заявке (trigger + reply'и),
        // отсортированы по sent_at для естественной хронологии. NULL sent_at
        // (редкие письма без Date header) уходят в конец.
        // Phase 1.9: visibleTo фильтрует чужие черновики (свои показываются).
        $this->thread = EmailMessage::query()
            ->where('related_request_id', $this->request->id)
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
            app(\App\Services\Request\AttentionService::class)
                ->onManagerOpened($this->request);

            // Implicit-state: ответственный менеджер (или acting через
            // delegation) открыл заявку — значит он начал работу. Без
            // кнопки «Начать работу» — статус подсасывается по факту.
            // РОП/директор-наблюдатель НЕ триггерит — они просто смотрят.
            if (
                in_array($this->request->status, [RequestStatus::Assigned, RequestStatus::New], true)
                && $this->request->isAccessibleBy($user)
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
        $isPrivileged = $user?->hasAnyRole([
            Role::HeadOfSales->value,
            Role::Director->value,
            Role::Secretary->value,
        ]);
        if (! $isPrivileged && $this->request->assigned_user_id !== $user?->id) {
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
                'assignedUser:id,name,email',
                'items' => fn ($q) => $this->applyItemsFilter($q)->orderBy('position'),
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
     * Парсим payload: `auto_sticky:{"linked":[id1,id2,...]}`.
     *
     * Старые backfilled-записи имеют просто `auto_sticky` (без `:`-payload) —
     * legacy=true, links пустой; UI покажет общий чип без deep-links.
     *
     * @return array{links: \Illuminate\Support\Collection, legacy: bool}
     */
    #[Computed]
    public function sticky(): array
    {
        $assignment = $this->request->assignments
            ->first(fn ($a) => str_starts_with((string) $a->reason, 'auto_sticky'));

        if (! $assignment) {
            return ['links' => collect(), 'legacy' => false];
        }

        $reason = (string) $assignment->reason;
        $colonAt = strpos($reason, ':');
        if ($colonAt === false) {
            // legacy: 'auto_sticky' без payload (165 backfill).
            return ['links' => collect(), 'legacy' => true];
        }

        $payload = substr($reason, $colonAt + 1);
        $data = json_decode($payload, true);
        $ids = is_array($data['linked'] ?? null) ? $data['linked'] : [];
        if (empty($ids)) {
            return ['links' => collect(), 'legacy' => true];
        }

        $links = \App\Models\Request::query()
            ->whereIn('id', $ids)
            ->orderByDesc('created_at')
            ->get(['id', 'internal_code', 'subject', 'status', 'client_name']);

        return ['links' => $links, 'legacy' => false];
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
        // Phase 1.10 + расширение: activity = assignments + stateChanges
        // + ВСЕ письма треда (не только initial).
        $activity = $this->request->assignments->count()
            + $this->request->stateChanges->count()
            + $this->thread->count();

        return [
            'overview'  => ['label' => 'Обзор',      'count' => null,         'disabled' => false],
            'thread'    => ['label' => 'Переписка',  'count' => $threadCount, 'disabled' => false],
            'items'     => ['label' => 'Позиции',    'count' => $items,       'disabled' => false],
            'suppliers' => ['label' => 'Поставщики', 'count' => null,         'disabled' => true],
            'activity'  => ['label' => 'Активность', 'count' => $activity,    'disabled' => false],
            'files'     => ['label' => 'Файлы',      'count' => $files,       'disabled' => false],
            'related'   => ['label' => 'Связанные',  'count' => null,         'disabled' => true],
        ];
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
     */
    private function collapseQuotedBlocks(string $html): string
    {
        if (stripos($html, '<blockquote') === false
            && stripos($html, 'gmail_quote') === false
            && stripos($html, 'yahoo_quoted') === false) {
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
        // Расширенный selector: любые top-level blockquote + Gmail/Yahoo div'ы.
        // ancestor::blockquote / ancestor::details — чтобы не оборачивать
        // повторно при перерендере.
        $nodes = $xpath->query(
            '//blockquote[not(ancestor::blockquote) and not(ancestor::details)]'
            . ' | //div[contains(@class, "gmail_quote") and not(ancestor::blockquote) and not(ancestor::details)]'
            . ' | //div[contains(@class, "yahoo_quoted") and not(ancestor::blockquote) and not(ancestor::details)]'
        );
        if ($nodes === false || $nodes->length === 0) {
            return $html;
        }

        foreach ($nodes as $bq) {
            /** @var \DOMElement $bq */
            $details = $doc->createElement('details');
            $details->setAttribute(
                'style',
                'margin-top:6px;'
            );

            $summary = $doc->createElement('summary', '· · · показать цитату');
            $summary->setAttribute(
                'style',
                'cursor:pointer;list-style:none;font-size:12px;color:#7280a0;'
                . 'user-select:none;padding:2px 0;outline:none;'
            );
            $details->appendChild($summary);

            $bq->parentNode->replaceChild($details, $bq);
            $details->appendChild($bq);
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
        if (! $this->request->isAccessibleBy($user) && ! $user->hasAnyRole([Role::HeadOfSales->value, Role::Director->value])) {
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
        if (! $this->request->isAccessibleBy($user) && ! $user->hasAnyRole([Role::HeadOfSales->value, Role::Director->value])) {
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
        return view('livewire.requests.detail');
    }
}
