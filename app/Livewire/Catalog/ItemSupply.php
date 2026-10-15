<?php

namespace App\Livewire\Catalog;

use App\Jobs\QuoteParts\SyncQuotePartsPoolJob;
use App\Models\SupplyGroup;
use App\Services\Catalog\SupplyGroupService;
use App\Services\Procurement\SupplierChoiceService;
use App\Services\Supplier\CatalogSupplierHistoryService;
use Livewire\Component;

/**
 * Блок «Снабжение» в карточке товара каталога: группы товаров (галочками — позиция может
 * быть в нескольких; пусто — снова по «Закупкам 1С»), у кого закупаем (выбор как в
 * «Снабжении», можно сменить) и история ценовых предложений поставщиков.
 */
class ItemSupply extends Component
{
    public int $catalogItemId;

    /** Показывать «Историю предложений» (там, где она уже выведена рядом, — нет). */
    public bool $withHistory = true;

    public bool $historyOpen = false;

    public bool $choosing = false;

    public function mount(int $catalogItemId, bool $withHistory = true, bool $historyOpen = false): void
    {
        $this->catalogItemId = $catalogItemId;
        $this->withHistory = $withHistory;
        $this->historyOpen = $historyOpen;
    }

    private function canEdit(): bool
    {
        return auth()->user()?->hasAnyRole(['procurement', 'head_of_sales', 'director', 'admin']) ?? false;
    }

    public function toggleGroup(string $code, SupplyGroupService $service): void
    {
        abort_unless($this->canEdit(), 403);
        $current = $service->groupsFor([$this->catalogItemId])[$this->catalogItemId] ?? [];
        $next = in_array($code, $current, true) ? array_values(array_diff($current, [$code])) : [...$current, $code];
        $service->setItemGroups($this->catalogItemId, $next);
        SyncQuotePartsPoolJob::dispatch();
    }

    /** Снова по «Закупкам 1С». */
    public function resetGroups(SupplyGroupService $service): void
    {
        abort_unless($this->canEdit(), 403);
        $service->setItemGroups($this->catalogItemId, []);
        SyncQuotePartsPoolJob::dispatch();
    }

    public function choose(string $key, SupplierChoiceService $choices): void
    {
        abort_unless($this->canEdit(), 403);
        $offer = collect($choices->currentOffers($this->catalogItemId))->firstWhere('key', $key);
        abort_if($offer === null, 404);
        $choices->choose($this->catalogItemId, $key, $offer, auth()->user());
        $this->choosing = false;
    }

    public function clearChoice(SupplierChoiceService $choices): void
    {
        abort_unless($this->canEdit(), 403);
        $choices->clear($this->catalogItemId);
    }

    public function render(SupplyGroupService $groups, SupplierChoiceService $choices)
    {
        $id = $this->catalogItemId;
        $rows = \Illuminate\Support\Facades\DB::table('catalog_item_supply_groups')->where('catalog_item_id', $id)->get(['group_code', 'source']);
        $choice = $choices->forItems([$id])->get($id);
        $offers = collect($choices->currentOffers($id));

        return view('livewire.catalog.item-supply', [
            'allGroups' => SupplyGroup::ordered(),
            'codes' => $rows->pluck('group_code')->all(),
            'manual' => $rows->contains('source', 'manual'),
            'choice' => $choice,
            'chosenOffer' => $choice ? $offers->firstWhere('key', $choice->supplier_key) : null,
            'offers' => $offers,
            'canEdit' => $this->canEdit(),
            'history' => $this->withHistory && $this->historyOpen ? (app(CatalogSupplierHistoryService::class)->forCatalogIds([$id])[$id] ?? null) : null,
        ]);
    }
}
