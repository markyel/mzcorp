<?php

namespace App\Livewire\Requests\Items;

use App\Models\CatalogItem;
use App\Models\RequestItem;
use App\Services\Catalog\CatalogSearchService;
use App\Services\Catalog\RequestItemEditor;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Modal manual link к каталогу (Priority 1).
 *
 * Поиск по `catalog_items` через CatalogSearchService (SQL ILIKE, без векторов).
 * Слушает event `open-catalog-link {itemId}`, открывает modal, оператор
 * вводит запрос, выбирает строку, жмёт «Привязать» — вызывается
 * RequestItemEditor::linkToCatalog с audit.
 *
 * Хранит только id (как ReassignDialog), не Eloquent-модель.
 */
class ItemCatalogLinkDialog extends Component
{
    public int $requestId;
    public ?int $requestItemId = null;
    public bool $open = false;
    public string $query = '';
    public ?int $selectedCatalogId = null;

    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
    }

    #[On('open-catalog-link')]
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
        // Pre-fill query из parsed_article (часто оператор именно его ищет).
        $this->query = (string) ($item->parsed_article ?: $item->parsed_name ?: '');
        $this->selectedCatalogId = $item->catalog_item_id;
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->requestItemId = null;
        $this->query = '';
        $this->selectedCatalogId = null;
    }

    public function selectCatalog(int $catalogId): void
    {
        $this->selectedCatalogId = $catalogId;
    }

    #[Computed]
    public function results()
    {
        if (mb_strlen(trim($this->query)) < 2) {
            return collect();
        }
        return app(CatalogSearchService::class)->search($this->query);
    }

    public function save(RequestItemEditor $editor): void
    {
        if (! $this->requestItemId || ! $this->selectedCatalogId) {
            $this->addError('query', 'Выберите позицию каталога из списка ниже.');
            return;
        }
        $item = RequestItem::query()
            ->where('request_id', $this->requestId)
            ->where('is_active', true)
            ->whereKey($this->requestItemId)
            ->first();
        $catalog = CatalogItem::find($this->selectedCatalogId);
        if (! $item || ! $catalog) {
            $this->addError('query', 'Позиция или каталожная карточка не найдены.');
            return;
        }

        $editor->linkToCatalog($item, $catalog, auth()->user());

        $this->dispatch('item-relinked');
        $this->close();
    }

    public function render()
    {
        return view('livewire.requests.items.item-catalog-link-dialog');
    }
}
