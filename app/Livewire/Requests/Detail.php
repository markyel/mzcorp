<?php

namespace App\Livewire\Requests;

use App\Enums\Role;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\RequestItem;
use App\Services\Catalog\RequestItemEditor;
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
            'context:id,request_id,analysis_status,equipment_units,llm_model_version,analyzed_at',
            'emailMessage.attachments:id,email_message_id,filename,size_bytes,mime_type,content_id,is_inline',
            'emailMessage.mailbox:id,email,name',
            'assignments' => fn ($q) => $q->orderByDesc('assigned_at'),
            'assignments.user:id,name',
            'assignments.assignedBy:id,name',
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
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
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
                'context:id,request_id,analysis_status,equipment_units,llm_model_version,analyzed_at',
                'emailMessage.attachments:id,email_message_id,filename,size_bytes,mime_type,content_id,is_inline',
                'emailMessage.mailbox:id,email,name',
                'assignments' => fn ($q) => $q->orderByDesc('assigned_at'),
                'assignments.user:id,name',
                'assignments.assignedBy:id,name',
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
        $activity = $this->request->assignments->count() + 1; // +1 за событие создания

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
     * Заменить cid:NNN в src/href HTML body на наш inline-роут.
     * Принимает любое EmailMessage из треда (не только trigger-email).
     */
    public function bodyHtmlFor(EmailMessage $email): ?string
    {
        if (! $email->body_html) {
            return null;
        }

        $messageId = $email->id;

        return preg_replace_callback(
            '/(src|href)\s*=\s*(["\'])cid:([^"\']+)\2/i',
            function ($m) use ($messageId) {
                $url = route('attachments.inline', [
                    'emailMessage' => $messageId,
                    'contentId' => rawurlencode($m[3]),
                ]);

                return $m[1] . '=' . $m[2] . $url . $m[2];
            },
            $email->body_html
        );
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
    public function handleItemChangedEvent(): void
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
