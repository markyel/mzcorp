<?php

namespace App\Livewire\Requests\Items;

use App\Models\RequestItem;
use App\Services\Catalog\RequestItemEditor;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Modal-диалог редактирования всех текстовых полей позиции (Priority 1).
 *
 * Inline в табе «Позиции» (single instance per Detail). Слушает event
 * `open-item-edit` с полем `itemId`, подгружает текущие значения и открывается.
 *
 * Паттерн как у ReassignDialog: храним только `int $requestItemId` (не Eloquent),
 * чтобы Livewire-дегидратация не ловила shadow от Illuminate\Http\Request.
 */
class ItemEditDialog extends Component
{
    public int $requestId;
    public ?int $requestItemId = null;
    public bool $open = false;

    #[Validate('nullable|string|max:500')]
    public string $parsedName = '';

    #[Validate('nullable|string|max:500')]
    public string $parsedArticle = '';

    #[Validate('nullable|string|max:250')]
    public string $parsedBrand = '';

    #[Validate('nullable|numeric|min:0')]
    public string $parsedQty = '';

    #[Validate('nullable|string|max:20')]
    public string $parsedUnit = '';

    #[Validate('nullable|string|max:1000')]
    public string $supplierNote = '';

    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
    }

    #[On('open-item-edit')]
    public function openForItem(int $itemId): void
    {
        $item = RequestItem::query()
            ->where('request_id', $this->requestId)
            ->where('is_active', true)
            ->whereKey($itemId)
            ->first();
        if (! $item) {
            return;
        }
        $this->requestItemId = $item->id;
        $this->parsedName = (string) ($item->parsed_name ?? '');
        $this->parsedArticle = (string) ($item->parsed_article ?? '');
        $this->parsedBrand = (string) ($item->parsed_brand ?? '');
        $this->parsedQty = $item->parsed_qty !== null
            ? rtrim(rtrim((string) $item->parsed_qty, '0'), '.')
            : '';
        $this->parsedUnit = (string) ($item->parsed_unit ?? '');
        $this->supplierNote = (string) ($item->supplier_note ?? '');
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->requestItemId = null;
    }

    public function save(RequestItemEditor $editor): void
    {
        $this->validate();
        if (! $this->requestItemId) {
            $this->close();
            return;
        }
        $item = RequestItem::query()
            ->where('request_id', $this->requestId)
            ->where('is_active', true)
            ->whereKey($this->requestItemId)
            ->first();
        if (! $item) {
            $this->close();
            return;
        }

        $editor->editFields($item, [
            'parsed_name' => $this->parsedName,
            'parsed_article' => $this->parsedArticle,
            'parsed_brand' => $this->parsedBrand,
            'parsed_qty' => $this->parsedQty,
            'parsed_unit' => $this->parsedUnit,
            'supplier_note' => $this->supplierNote,
        ], auth()->user());

        $this->dispatch('item-edited');
        $this->close();
    }

    public function render()
    {
        return view('livewire.requests.items.item-edit-dialog');
    }
}
