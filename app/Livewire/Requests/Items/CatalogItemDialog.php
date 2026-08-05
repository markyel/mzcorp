<?php

namespace App\Livewire\Requests\Items;

use App\Models\CatalogItem;
use App\Models\CatalogPriceChange;
use App\Models\IqotPosition;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Модалка полной карточки товара каталога. Открывается событием
 * `open-catalog-item` с `catalogItemId` (клик по каталожному названию
 * сматченной позиции в заявке). Рендерит тот же партиал, что и раскрытая
 * строка поиска каталога (_catalog-item-detail) — единый источник вёрстки.
 * Квик-вью: IQOT только чтение (без кнопки «анализ»).
 */
class CatalogItemDialog extends Component
{
    public ?int $catalogItemId = null;

    public bool $open = false;

    #[On('open-catalog-item')]
    public function openFor(int $catalogItemId): void
    {
        $this->catalogItemId = $catalogItemId;
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->catalogItemId = null;
    }

    #[Computed]
    public function catalogItem(): ?CatalogItem
    {
        return $this->catalogItemId ? CatalogItem::find($this->catalogItemId) : null;
    }

    #[Computed]
    public function canIqot(): bool
    {
        return auth()->user()?->hasAnyRole(['head_of_sales', 'director', 'admin']) ?? false;
    }

    #[Computed]
    public function iqotPosition(): ?IqotPosition
    {
        if (! $this->canIqot || $this->catalogItemId === null) {
            return null;
        }

        return IqotPosition::where('catalog_item_id', $this->catalogItemId)->first();
    }

    #[Computed]
    public function lastPriceChange(): ?CatalogPriceChange
    {
        if ($this->catalogItemId === null) {
            return null;
        }

        return CatalogPriceChange::query()
            ->where('catalog_item_id', $this->catalogItemId)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->first();
    }

    public function render()
    {
        return view('livewire.requests.items.catalog-item-dialog');
    }
}
