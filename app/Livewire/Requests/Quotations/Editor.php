<?php

namespace App\Livewire\Requests\Quotations;

use App\Enums\Role;
use App\Models\ClientContact;
use App\Models\IqotPosition;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Request as RequestModel;
use App\Models\RequestItem;
use App\Services\Quotations\QuotationService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * UI редактор КП (исходящего, наш Quotation клиенту).
 *
 * Один Livewire-компонент на одну заявку, рендерится внутри таба «КП»
 * в Detail.blade.php. Управляет жизненным циклом active draft'а +
 * показывает историю версий (frozen / sent / accepted / rejected /
 * cancelled).
 *
 * Permission:
 *  - assigned manager / acting (delegation) / privileged могут
 *    создавать/редактировать/закреплять/отменять.
 *  - все остальные — read-only (видят таб, но disabled-кнопки).
 *
 * Поля редактирования (in-place, сразу пишутся через QuotationService):
 *  - quotation.discount_percent (общая) + быстрые пресеты 0/3/5/7/10/15/20
 *  - quotation.valid_days
 *  - quotation.recipient_name / inn / address / card_text
 *  - per-item: qty / discount_percent (override) / delivery_text / notes
 *
 * Actions:
 *  - createDraft() — создать первый draft или новую версию после отправки
 *  - refreshPrices() — пере-snapshot catalog в текущий draft
 *  - freezeVersion() — закрепить как immutable v+1 (новый draft)
 *  - cancelDraft() — отменить, без новой версии
 *
 * Send/PDF — Фазы 3/4.
 */
class Editor extends Component
{
    public int $requestId;

    /** Просмотр конкретной версии (id) — null = active draft / latest non-cancelled. */
    public ?int $viewQuotationId = null;

    /** Поиск организации-получателя (если нужной нет среди привязанных к клиенту). */
    public string $organizationSearch = '';

    #[Computed]
    public function request(): RequestModel
    {
        return RequestModel::query()
            ->with(['items.catalogItem', 'assignedUser'])
            ->findOrFail($this->requestId);
    }

    /**
     * Все версии КП этой заявки (sorted version desc).
     * @return \Illuminate\Database\Eloquent\Collection<int, Quotation>
     */
    #[Computed]
    public function versions()
    {
        return $this->request->quotations()->with('items')->get();
    }

    /**
     * Активная версия для редактирования: текущий draft (если есть),
     * иначе latest non-cancelled (для просмотра).
     */
    #[Computed]
    public function activeQuotation(): ?Quotation
    {
        if ($this->viewQuotationId) {
            return $this->versions->firstWhere('id', $this->viewQuotationId);
        }
        // 1. Текущий draft
        $draft = $this->versions->first(fn ($q) => $q->status->value === 'draft');
        if ($draft) {
            return $draft;
        }
        // 2. Latest sent/accepted/rejected (для просмотра)
        return $this->versions->first(fn ($q) => $q->status->value !== 'cancelled');
    }

    /**
     * Сматченные RequestItem'ы — попадут в КП.
     */
    #[Computed]
    public function matchedItems()
    {
        return $this->request->items
            ->where('is_active', true)
            ->whereNotNull('catalog_item_id');
    }

    /**
     * Несматченные позиции заявки — НЕ попадут в КП (warning).
     */
    #[Computed]
    public function unmatchedItems()
    {
        return $this->request->items
            ->where('is_active', true)
            ->whereNull('catalog_item_id');
    }

    /**
     * Карта catalog_item_id → IqotPosition со СВЕЖИМ отчётом для позиций этой
     * заявки. Наличие записи = по позиции есть актуальный анализ цен конкурентов
     * (подсветка в строках КП со ссылкой на раздел IQOT).
     *
     * @return \Illuminate\Support\Collection<int, IqotPosition>
     */
    #[Computed]
    public function iqotByCatalogId()
    {
        $ids = $this->request->items->pluck('catalog_item_id')->filter()->unique()->all();
        if ($ids === []) {
            return collect();
        }
        $fresh = (int) app_setting('iqot.report_fresh_days', config('services.iqot.report_fresh_days', 90));

        return IqotPosition::whereIn('catalog_item_id', $ids)
            ->withFreshReport($fresh)
            ->get()
            ->keyBy('catalog_item_id');
    }

    #[Computed]
    public function canEdit(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if ($user->hasAnyRole([Role::HeadOfSales->value, Role::Director->value, Role::Admin->value])) {
            return true;
        }
        $req = $this->request;
        // owner OR acting (delegation)
        return method_exists($req, 'isAccessibleBy')
            ? $req->isAccessibleBy($user)
            : $req->assigned_user_id === $user->id;
    }

    public function createDraft(QuotationService $svc): void
    {
        $this->ensureCanEdit();
        if ($this->matchedItems->isEmpty()) {
            $this->dispatch('toast', message: 'Нет сматченных позиций каталога — нечего предложить.', type: 'error');

            return;
        }
        $q = $svc->createDraft($this->request, auth()->user());
        $this->viewQuotationId = $q->id;
        unset($this->versions, $this->activeQuotation);
        $this->dispatch('toast', message: "Создан черновик {$q->internal_code}", type: 'success');
    }

    public function refreshPrices(QuotationService $svc): void
    {
        $this->ensureCanEdit();
        $q = $this->activeQuotation;
        if (! $q || ! $q->status->isEditable()) {
            return;
        }
        $changed = $svc->refreshPrices($q);
        $msg = $changed > 0
            ? "Обновлены цены {$changed} позиций"
            : 'Цены не изменились (каталог не обновлялся со времени последнего снапшота)';
        unset($this->versions, $this->activeQuotation);
        $this->dispatch('toast', message: $msg, type: 'success');
    }

    /**
     * Создать новый вариант (v+1) на основе текущего draft'а. Менеджер
     * жмёт когда хочет предложить клиенту альтернативную комплектацию.
     * Правки внутри одной версии — in-place auto-save, отдельной кнопки
     * «сохранить» нет (всё пишется через wire:blur).
     */
    public function createNextVersion(QuotationService $svc): void
    {
        $this->ensureCanEdit();
        $current = $this->activeQuotation;
        if (! $current || ! $current->status->isEditable()) {
            return;
        }
        $new = $svc->createNextVersion($current, auth()->user());
        $this->viewQuotationId = $new->id;
        unset($this->versions, $this->activeQuotation);
        $this->dispatch('toast', message: "Создан новый вариант v{$new->version} (на основе v{$current->version})", type: 'success');
    }

    public function cancelDraft(QuotationService $svc): void
    {
        $this->ensureCanEdit();
        $q = $this->activeQuotation;
        if (! $q || ! $q->status->isEditable()) {
            return;
        }
        $svc->markCancelled($q, 'Отменено менеджером');
        $this->viewQuotationId = null;
        unset($this->versions, $this->activeQuotation);
        $this->dispatch('toast', message: "Черновик {$q->internal_code} отменён", type: 'success');
    }

    /**
     * Phase 4: «📨 Отправить КП клиенту».
     *
     * Что делает:
     *  1. Генерирует PDF через QuotationPdfService::render и сохраняет его
     *     в storage/app/quotations/{q.id}_v{q.version}.pdf (idempotent —
     *     если файл уже есть, переиспользует).
     *  2. Ищет последнее inbound-письмо клиента в этой заявке. Если есть —
     *     создаёт reply-draft (сохраняет thread по In-Reply-To/References),
     *     иначе — compose-draft с темой `[M-YYYY-NNNN] subject`.
     *  3. Заполняет body draft'а из шаблона (config('services.quotations.email_body_template')).
     *  4. Создаёт EmailAttachment с PDF + прописывает marker
     *     `{type: 'quotation_sent', quotation_id: N}` в `detected_artifacts`.
     *     Post-send hook в ComposeForm::applyPostSendHooks подхватит marker:
     *       - Quotation::markSent() — status=sent + sent_at + sent_email_message_id
     *       - Request → Quoted через RequestStateService
     *  5. Дисптачит `quotation-send-ready` → Detail переключает таб на
     *     «Переписка» + диспатчит `open-draft` → ComposeForm раскрывает draft.
     *     Менеджер может править recipients/body перед отправкой.
     *
     * Permission: assigned manager / acting / privileged (через ensureCanEdit).
     * Если КП в финальном статусе (accepted/rejected/cancelled) — отказ.
     */
    public function sendQuotation(
        int $quotationId,
        \App\Services\Quotations\QuotationPdfService $pdfSvc,
        \App\Services\Mail\EmailDraftService $draftSvc,
    ): void {
        $this->ensureCanEdit();

        $q = $this->request->quotations()->whereKey($quotationId)->with('items')->first();
        if (! $q) {
            $this->dispatch('toast', message: 'КП не найдена.', type: 'error');
            return;
        }

        // editable (draft) ИЛИ ещё не отправленная sent (resend). Финальные
        // accepted/rejected/cancelled — нельзя; создайте новую версию.
        $canSend = $q->status->isEditable() || $q->status === \App\Enums\QuotationStatus::Sent;
        if (! $canSend) {
            $this->dispatch('toast', message: 'КП в финальном статусе (' . $q->status->value . '). Создайте новую версию.', type: 'error');
            return;
        }

        // 1. PDF binary → storage.
        try {
            $pdfBinary = $pdfSvc->render($q, isolated: true);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Editor::sendQuotation: PDF render failed', [
                'quotation_id' => $q->id,
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('toast', message: 'Ошибка генерации PDF: ' . $e->getMessage(), type: 'error');
            return;
        }
        $filename = $pdfSvc->filename($q);
        $relativePath = sprintf('quotations/%d_v%d.pdf', $q->id, $q->version);
        \Illuminate\Support\Facades\Storage::disk('local')->put($relativePath, $pdfBinary);

        // 2. Найти last inbound-письмо клиента (для thread-reply).
        $lastInbound = \App\Models\EmailMessage::query()
            ->where('related_request_id', $this->request->id)
            ->where('direction', \App\Enums\MailDirection::Inbound->value)
            ->where('is_draft', false)
            ->orderByDesc('id')
            ->first();

        // 3. Создать draft.
        try {
            $draft = $lastInbound
                ? $draftSvc->createReply($this->request, $lastInbound, auth()->user(), replyAll: false)
                : $draftSvc->createCompose($this->request, auth()->user());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Editor::sendQuotation: createDraft failed', [
                'quotation_id' => $q->id,
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('toast', message: 'Не удалось создать черновик: ' . $e->getMessage(), type: 'error');
            return;
        }

        // 4. Body из шаблона + marker для post-send hook.
        $template = (string) config(
            'services.quotations.email_body_template',
            "Здравствуйте, {client_name}!\n\nВысылаем коммерческое предложение по запросу {internal_code}.\nИтого: {total} ₽ (вкл. НДС).\nСрок действия: {valid_until}.\n\nС уважением,\n{sender_name}"
        );
        $body = strtr($template, [
            '{client_name}' => $this->request->client_name ?: 'коллеги',
            '{internal_code}' => $this->request->internal_code,
            '{quotation_code}' => $q->internal_code . ' v' . $q->version,
            '{total}' => number_format((float) $q->total, 2, '.', ' '),
            '{valid_until}' => $q->valid_until?->format('d.m.Y') ?? '—',
            '{sender_name}' => auth()->user()->name ?? '',
        ]);

        $artifacts = is_array($draft->detected_artifacts ?? null) ? $draft->detected_artifacts : [];
        $artifacts[] = [
            'type' => 'quotation_sent',
            'quotation_id' => $q->id,
            'transition_to_status' => 'quoted',
            'pdf_path' => $relativePath,
        ];

        $draft->forceFill([
            'body_plain' => $body,
            'detected_artifacts' => $artifacts,
        ])->save();

        // 5. Attach PDF.
        $draft->attachments()->create([
            'filename' => $filename,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($pdfBinary),
            'content_id' => null,
            'file_path' => $relativePath,
            'disk' => 'local',
            'is_inline' => false,
        ]);

        // 6. Открыть draft в Compose-табе (через Detail listener).
        $this->dispatch('quotation-send-ready', draftId: $draft->id, requestId: $this->request->id);
        $this->dispatch('toast', message: "Черновик готов: {$q->internal_code} v{$q->version}. Проверьте и отправьте.", type: 'success');
    }

    public function switchToVersion(int $quotationId): void
    {
        $exists = $this->versions->firstWhere('id', $quotationId);
        if ($exists) {
            $this->viewQuotationId = $quotationId;
        }
        unset($this->activeQuotation);
    }

    /**
     * Update общих полей quotation (recipient_name, inn, address, valid_days, discount_percent, notes).
     */
    public function updateQuotationField(string $field, $value, QuotationService $svc): void
    {
        $this->ensureCanEdit();
        $q = $this->activeQuotation;
        if (! $q || ! $q->status->isEditable()) {
            return;
        }
        $allowed = ['recipient_name', 'recipient_inn', 'recipient_address',
            'recipient_card_text', 'valid_days', 'discount_percent', 'notes', 'client_comment'];
        if (! in_array($field, $allowed, true)) {
            return;
        }
        // Sanitize types
        if (in_array($field, ['valid_days'], true)) {
            $value = max(1, min(365, (int) $value));
        }
        if ($field === 'discount_percent') {
            $value = max(0, min(100, (float) str_replace(',', '.', (string) $value)));
        }
        $q->forceFill([$field => $value])->save();
        if ($field === 'discount_percent') {
            $svc->recalcTotals($q->fresh('items'));
        }
        unset($this->versions, $this->activeQuotation);
    }

    /**
     * Организации этого клиента — для быстрой подстановки получателя КП.
     * Точная привязка заявки (organization_id) + организации контакта по
     * client_email (один email может быть у нескольких организаций — частый
     * кейс, см. [[clients-section]]).
     *
     * @return \Illuminate\Support\Collection<int, Organization>
     */
    #[Computed]
    public function clientOrganizations()
    {
        $req = $this->request;
        $orgs = collect();

        if ($req->organization_id) {
            $o = Organization::find($req->organization_id);
            if ($o) {
                $orgs->push($o);
            }
        }

        $email = mb_strtolower(trim((string) $req->client_email));
        if ($email !== '') {
            $contact = ClientContact::whereRaw('lower(email) = ?', [$email])->first();
            if ($contact) {
                foreach ($contact->organizations as $o) {
                    $orgs->push($o);
                }
            }
        }

        return $orgs->unique('id')->values();
    }

    /**
     * Поиск любой известной организации (если нужной нет среди привязанных).
     *
     * @return \Illuminate\Support\Collection<int, Organization>
     */
    #[Computed]
    public function searchedOrganizations()
    {
        $s = trim($this->organizationSearch);
        if (mb_strlen($s) < 2) {
            return collect();
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
        $exclude = $this->clientOrganizations->pluck('id')->all();

        return Organization::query()
            ->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('inn', 'ilike', $like))
            ->whereNotIn('id', $exclude ?: [0])
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'inn', 'discount_percent']);
    }

    /**
     * Подставить получателя КП из организации: реквизиты (наименование/ИНН/
     * адрес/банк) + НАЗНАЧЕННАЯ скидка организации (учитывается в ценах через
     * recalcTotals; реальную скидку показывает realDiscountPercent).
     */
    public function applyOrganization(int $orgId, QuotationService $svc): void
    {
        $this->ensureCanEdit();
        $q = $this->activeQuotation;
        if (! $q || ! $q->status->isEditable()) {
            return;
        }
        $org = Organization::find($orgId);
        if (! $org) {
            return;
        }

        $discount = max(0.0, min(100.0, (float) ($org->discount_percent ?? 0)));
        $q->forceFill([
            'recipient_name' => $org->name,
            'recipient_inn' => $org->inn,
            'recipient_address' => $org->address,
            'recipient_card_text' => $org->requisites_text,
            'discount_percent' => $discount,
        ])->save();
        $svc->recalcTotals($q->fresh('items'));

        $this->organizationSearch = '';
        unset($this->versions, $this->activeQuotation);
        $this->dispatch('toast', message: 'Получатель: ' . $org->name
            . ($discount > 0 ? ', скидка ' . rtrim(rtrim(number_format($discount, 2, '.', ''), '0'), '.') . '% применена' : ''),
            type: 'success');
    }

    /**
     * Update per-item: qty / discount_percent / delivery_text / notes.
     */
    public function updateItemField(int $itemId, string $field, $value, QuotationService $svc): void
    {
        $this->ensureCanEdit();
        $q = $this->activeQuotation;
        if (! $q || ! $q->status->isEditable()) {
            return;
        }
        $item = $q->items->firstWhere('id', $itemId);
        if (! $item) {
            return;
        }
        $allowed = ['qty', 'discount_percent', 'delivery_text', 'notes'];
        if (! in_array($field, $allowed, true)) {
            return;
        }
        if ($field === 'qty') {
            $value = max(0.001, (float) str_replace(',', '.', (string) $value));
        }
        if ($field === 'discount_percent') {
            $value = $value === '' || $value === null
                ? null
                : max(0, min(100, (float) str_replace(',', '.', (string) $value)));
        }
        $item->forceFill([$field => $value])->save();
        $svc->recalcTotals($q->fresh('items'));
        unset($this->versions, $this->activeQuotation);
    }

    public function removeItem(int $itemId, QuotationService $svc): void
    {
        $this->ensureCanEdit();
        $q = $this->activeQuotation;
        if (! $q || ! $q->status->isEditable()) {
            return;
        }
        $item = $q->items->firstWhere('id', $itemId);
        if (! $item) {
            return;
        }
        $item->delete();
        $svc->recalcTotals($q->fresh('items'));
        unset($this->versions, $this->activeQuotation);
        $this->dispatch('toast', message: 'Позиция удалена из КП', type: 'success');
    }

    /**
     * Добавить в КП позицию из сматченного RequestItem'а, которой ещё нет в текущем draft'е.
     */
    public function addItemFromRequest(int $requestItemId, QuotationService $svc): void
    {
        $this->ensureCanEdit();
        $q = $this->activeQuotation;
        if (! $q || ! $q->status->isEditable()) {
            return;
        }
        $reqItem = $this->request->items->firstWhere('id', $requestItemId);
        if (! $reqItem || ! $reqItem->catalog_item_id) {
            $this->dispatch('toast', message: 'Позиция не сматчена с каталогом', type: 'error');

            return;
        }
        if ($q->items->contains('request_item_id', $reqItem->id)) {
            $this->dispatch('toast', message: 'Позиция уже в КП', type: 'info');

            return;
        }
        $cat = $reqItem->catalogItem;
        if (! $cat) {
            return;
        }
        $position = ($q->items->max('position') ?? 0) + 1;

        // qty остаётся в ШТУКАХ (parsed_qty), метраж снапшотится отдельно в
        // piece_length. bill_by_length = как менеджер выставил единицу цены в
        // карточке позиции (billing_unit): если effectiveUnit совпадает с
        // parsed_length_unit — цена за метр, сумму умножаем на длину. КП тогда
        // показывает «6 шт × 55 м», не схлопывая штуки в метраж. Симметрично
        // QuotationService::autoFillItemsFromRequest. См. M-2026-7784 (раньше
        // qty схлопывался в 330 и терялось «6 кусков»); M-2026-1478 (цена за звено).
        $isMeasured = $reqItem->isMeasured();
        $billByLength = $isMeasured
            && mb_strtolower(trim((string) $reqItem->effectiveUnit()))
                === mb_strtolower(trim((string) $reqItem->parsed_length_unit));

        $item = new \App\Models\QuotationItem([
            'quotation_id' => $q->id,
            'position' => $position,
            'request_item_id' => $reqItem->id,
            'catalog_item_id' => $cat->id,
            'qty' => (float) ($reqItem->parsed_qty ?: 1),
            'unit' => $reqItem->parsed_unit ?: 'шт',
            'piece_length' => $isMeasured ? (float) $reqItem->parsed_length : null,
            'piece_length_unit' => $isMeasured ? $reqItem->parsed_length_unit : null,
            'bill_by_length' => $billByLength,
            'catalog_unit_price' => (float) ($cat->price ?: 0),
            'catalog_price_min' => $cat->price_min !== null ? (float) $cat->price_min : null,
            'catalog_lead_time_days' => $cat->lead_time_days,
            'catalog_in_stock' => ((int) ($cat->stock_available ?? 0)) > 0,
            'catalog_stock_available' => $cat->stock_available,
            'snapshot_name' => (string) $cat->name,
            'snapshot_sku' => $cat->sku,
            'snapshot_brand' => $cat->brand,
            'snapshot_brand_article' => $cat->brand_article,
            'snapshot_photo_url' => $cat->photo_url,
        ]);
        if (! $item->catalog_in_stock && $cat->lead_time_days) {
            $weeks = (int) ceil($cat->lead_time_days / 7);
            $item->delivery_text = "Под заказ {$weeks} нед";
        }
        $item->save();
        $svc->recalcTotals($q->fresh('items'));
        unset($this->versions, $this->activeQuotation);
        $this->dispatch('toast', message: 'Позиция добавлена', type: 'success');
    }

    private function ensureCanEdit(): void
    {
        if (! $this->canEdit) {
            abort(403, 'Нет прав редактировать КП');
        }
    }

    #[On('request-state-changed')]
    public function onStateChanged(): void
    {
        unset($this->request, $this->versions, $this->activeQuotation, $this->matchedItems, $this->unmatchedItems, $this->iqotByCatalogId);
    }

    public function render()
    {
        return view('livewire.requests.quotations.editor');
    }
}
