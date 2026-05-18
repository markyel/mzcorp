<?php

namespace App\Livewire\Requests\Quotes;

use App\Models\OutboundQuoteItem;
use App\Models\Request as RequestModel;
use App\Models\RequestItem;
use App\Services\Quotes\OutboundQuoteItemEditor;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Диалог ручного доматчинга строки исходящего КП к позиции заявки.
 *
 * Открывается из таба «КП» в карточке заявки кнопкой «🔗 Привязать» или
 * «🔄 Изменить позицию» рядом со строкой OutboundQuoteItem. Показывает
 * список active RequestItem'ов заявки с поиском по name/article + чипом
 * «уже сматчено» для тех, кто уже является target'ом другой строки КП.
 *
 * После save() → OutboundQuoteItemEditor::linkToRequestItem (auto-enrich
 * catalog_item_id если null) → dispatch('quote-item-rematched') →
 * родительский Detail::handleQuoteItemRematched → reloadRequest.
 */
class MatchRequestItemDialog extends Component
{
    /** @var int хранение id вместо Eloquent — избегаем shadow-name проблем. */
    public int $requestId;

    public bool $open = false;
    public ?int $quoteItemId = null;
    public ?int $selectedRequestItemId = null;
    public string $search = '';

    public function mount(RequestModel $request): void
    {
        $this->requestId = $request->id;
    }

    private function request(): RequestModel
    {
        return RequestModel::findOrFail($this->requestId);
    }

    /**
     * Открыть диалог для конкретной строки КП. Триггер из blade через
     * `$dispatch('open-quote-match', { quoteItemId: N })`.
     */
    #[\Livewire\Attributes\On('open-quote-match')]
    public function show(int $quoteItemId): void
    {
        $this->quoteItemId = $quoteItemId;
        $this->selectedRequestItemId = null;
        $this->search = '';
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->quoteItemId = null;
    }

    public function save(OutboundQuoteItemEditor $editor): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(403);
        }

        if ($this->selectedRequestItemId === null) {
            $this->addError('selectedRequestItemId', 'Выберите позицию заявки.');

            return;
        }

        $request = $this->request();
        if (! $request->isAccessibleBy($user)) {
            abort(403);
        }

        $quoteItem = OutboundQuoteItem::query()
            ->whereHas('quote', fn ($q) => $q->where('request_id', $request->id))
            ->whereKey($this->quoteItemId)
            ->first();

        if (! $quoteItem) {
            $this->addError('selectedRequestItemId', 'Строка КП не найдена.');

            return;
        }

        $requestItem = RequestItem::where('request_id', $request->id)
            ->where('is_active', true)
            ->whereKey($this->selectedRequestItemId)
            ->first();

        if (! $requestItem) {
            $this->addError('selectedRequestItemId', 'Позиция заявки не найдена или удалена.');

            return;
        }

        try {
            $editor->linkToRequestItem($quoteItem, $requestItem, $user);
        } catch (\Throwable $e) {
            $this->addError('selectedRequestItemId', 'Не удалось привязать: '.$e->getMessage());

            return;
        }

        $this->open = false;
        session()->flash('status', sprintf(
            'Строка КП #%d привязана к позиции №%s заявки.',
            $quoteItem->position,
            $requestItem->position ?? '?'
        ));

        // Триггерим reloadRequest в родительском Detail — пересчитает Hero
        // chip «КП N/M», обновит таб «КП».
        $this->dispatch('quote-item-rematched');
    }

    /**
     * Отвязать строку КП от текущего RequestItem (вернуть в unmatched).
     * Триггер из blade через `$wire.unlink(quoteItemId)`.
     */
    public function unlink(int $quoteItemId, OutboundQuoteItemEditor $editor): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(403);
        }
        $request = $this->request();
        if (! $request->isAccessibleBy($user)) {
            abort(403);
        }

        $quoteItem = OutboundQuoteItem::query()
            ->whereHas('quote', fn ($q) => $q->where('request_id', $request->id))
            ->whereKey($quoteItemId)
            ->first();

        if (! $quoteItem) {
            return;
        }

        try {
            $editor->unlinkFromRequestItem($quoteItem, $user);
        } catch (\Throwable $e) {
            session()->flash('error', 'Не удалось отвязать: '.$e->getMessage());

            return;
        }

        session()->flash('status', 'Строка КП отвязана от позиции заявки.');
        $this->dispatch('quote-item-rematched');
    }

    /**
     * Список active RequestItem'ов заявки. С чипом «уже сматчено» если на
     * него уже указывает какой-то OutboundQuoteItem (visual hint, не блокирует
     * выбор — один RequestItem валидно target'ом нескольких quote_items
     * при split delivery).
     */
    #[Computed]
    public function items()
    {
        $request = $this->request();
        $matchedRequestIds = OutboundQuoteItem::query()
            ->whereHas('quote', fn ($q) => $q->where('request_id', $request->id))
            ->whereNotNull('matched_request_item_id')
            ->pluck('matched_request_item_id')
            ->countBy()
            ->all();

        $items = $request->items()
            ->where('is_active', true)
            ->orderBy('position')
            ->get();

        if ($this->search !== '') {
            $needle = mb_strtolower(trim($this->search));
            $items = $items->filter(function (RequestItem $ri) use ($needle) {
                foreach ([$ri->parsed_name, $ri->parsed_article, $ri->parsed_brand] as $field) {
                    if ($field !== null && str_contains(mb_strtolower((string) $field), $needle)) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        return $items->map(fn (RequestItem $ri) => [
            'id' => $ri->id,
            'position' => $ri->position,
            'name' => $ri->parsed_name,
            'article' => $ri->parsed_article,
            'brand' => $ri->parsed_brand,
            'qty' => $ri->parsed_qty,
            'has_catalog' => $ri->catalog_item_id !== null,
            'quote_match_count' => $matchedRequestIds[$ri->id] ?? 0,
        ]);
    }

    #[Computed]
    public function quoteItem(): ?OutboundQuoteItem
    {
        if ($this->quoteItemId === null) {
            return null;
        }

        return OutboundQuoteItem::with(['catalogItem:id,sku,name', 'quote'])
            ->find($this->quoteItemId);
    }

    public function render()
    {
        return view('livewire.requests.quotes.match-request-item-dialog');
    }
}
