<?php

namespace App\Livewire\QuoteParts;

use App\Models\CatalogItem;
use App\Services\Catalog\CatalogSearchService;
use App\Services\Catalog\CatalogSkuList;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * «Запросить расценку на позиции каталога» в разделе «QuoteParts» (11.11.2026), как в «Снабжении»:
 * любая позиция каталога — поиском или списком M-артикулов, не только из «К актуализации».
 * Найденное уходит в панель «Запрос поставщикам» (PriceRequest).
 */
class CatalogPicker extends Component
{
    public string $catalogSearch = '';

    /** Список артикулов, вставленный разом (столбец из Excel, через запятую и т.п.). */
    public string $skuList = '';

    /** Итог последнего добавления списком: сколько передано в запрос, чего нет в каталоге. */
    public ?array $skuListReport = null;

    /** @return \Illuminate\Support\Collection<int, CatalogItem> */
    #[Computed]
    public function catalogSearchResults()
    {
        $q = trim($this->catalogSearch);

        return mb_strlen($q) < 2 ? collect() : app(CatalogSearchService::class)->search($q, 10);
    }

    public function addCatalogItem(int $cid): void
    {
        if ($cid > 0 && CatalogItem::whereKey($cid)->exists()) {
            $this->dispatch('qp-price-request', cids: [$cid])->to(PriceRequest::class);
            $this->catalogSearch = '';
            unset($this->catalogSearchResults);
        }
    }

    public function addCatalogSkus(): void
    {
        ['wanted' => $wanted, 'found' => $found, 'missing' => $missing] = CatalogSkuList::resolve($this->skuList);
        if ($wanted === []) {
            $this->skuListReport = ['added' => 0, 'missing' => [], 'empty' => true];

            return;
        }
        if ($found->isNotEmpty()) {
            $this->dispatch('qp-price-request', cids: $found->pluck('id')->map(fn ($v) => (int) $v)->values()->all())->to(PriceRequest::class);
        }
        $this->skuListReport = ['added' => $found->count(), 'missing' => $missing, 'empty' => false];
        // Остаются ненайденные — их удобно поправить и добавить ещё раз.
        $this->skuList = implode("\n", $missing);
    }

    public function render()
    {
        return view('livewire.quoteparts.catalog-picker');
    }
}
