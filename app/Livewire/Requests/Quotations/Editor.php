<?php

namespace App\Livewire\Requests\Quotations;

use App\Enums\Role;
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

    #[Computed]
    public function canEdit(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if ($user->hasAnyRole([Role::HeadOfSales->value, Role::Director->value])) {
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

    public function freezeVersion(QuotationService $svc): void
    {
        $this->ensureCanEdit();
        $current = $this->activeQuotation;
        if (! $current || ! $current->status->isEditable()) {
            return;
        }
        $new = $svc->freezeVersion($current, auth()->user());
        $this->viewQuotationId = $new->id;
        unset($this->versions, $this->activeQuotation);
        $this->dispatch('toast', message: "Версия #{$current->version} закреплена, начат draft v{$new->version}", type: 'success');
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
            'recipient_card_text', 'valid_days', 'discount_percent', 'notes'];
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
        $item = new \App\Models\QuotationItem([
            'quotation_id' => $q->id,
            'position' => $position,
            'request_item_id' => $reqItem->id,
            'catalog_item_id' => $cat->id,
            'qty' => (float) ($reqItem->parsed_qty ?: 1),
            'unit' => $reqItem->parsed_unit ?: 'шт',
            'catalog_unit_price' => (float) ($cat->price ?: 0),
            'catalog_price_min' => $cat->price_min !== null ? (float) $cat->price_min : null,
            'catalog_lead_time_days' => $cat->lead_time_days,
            'catalog_in_stock' => ((int) ($cat->stock_available ?? 0)) > 0,
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
        unset($this->request, $this->versions, $this->activeQuotation, $this->matchedItems, $this->unmatchedItems);
    }

    public function render()
    {
        return view('livewire.requests.quotations.editor');
    }
}
