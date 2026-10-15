<?php

namespace App\Livewire\QuoteParts;

use App\Enums\SupplyGroup;
use App\Jobs\QuoteParts\SyncQuotePartsPoolJob;
use App\Models\CatalogItem;
use App\Services\Catalog\SupplyGroupService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Вкладка «Группы» раздела QuoteParts: регионы поставщиков из «Закупок 1С» (авто, можно
 * поправить), сколько позиций в группах Китай / Россия / Европа, пересчёт и группы одной
 * позиции руками. Группы уходят в QuoteParts вместе с позициями пула.
 */
class SupplyGroups extends Component
{
    use WithPagination;

    /** '' — все, cn / ru / eu, none — без региона */
    #[Url(as: 'region', except: '')]
    public string $region = '';

    #[Url(as: 'sq', except: '')]
    public string $search = '';

    /** M-артикул позиции, чьи группы правим руками. */
    public string $sku = '';

    /** @var list<string> */
    public array $itemGroups = [];

    public ?int $itemId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['director', 'admin']), 403);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['region', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function setRegion(int $id, string $region, SupplyGroupService $service): void
    {
        $service->setRegion($id, $region === '' ? null : $region, auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Регион сохранён. Группы позиций обновятся после «Пересчитать».');
    }

    public function rebuild(SupplyGroupService $service): void
    {
        $service->refreshRegions();
        $counts = $service->rebuild();
        // Позиции пула уйдут в QuoteParts с новыми группами (изменилась карточка — повторная отправка).
        SyncQuotePartsPoolJob::dispatch();
        $this->dispatch('toast', type: 'success', message: 'Пересчитано: '.collect(SupplyGroup::cases())->map(fn ($g) => $g->label().' '.($counts[$g->value] ?? 0))->implode(', ').'.');
    }

    public function findItem(SupplyGroupService $service): void
    {
        $item = CatalogItem::query()->where('sku', mb_strtoupper(trim($this->sku)))->first(['id', 'sku']);
        $this->itemId = $item?->id;
        $this->itemGroups = $item ? ($service->groupsFor([$item->id])[$item->id] ?? []) : [];
        if ($item === null) {
            $this->addError('sku', 'Позиция с таким артикулом не найдена.');
        }
    }

    public function saveItemGroups(SupplyGroupService $service): void
    {
        abort_if($this->itemId === null, 404);
        $service->setItemGroups($this->itemId, $this->itemGroups);
        $this->itemGroups = $service->groupsFor([$this->itemId])[$this->itemId] ?? [];
        SyncQuotePartsPoolJob::dispatch();
        $this->dispatch('toast', type: 'success', message: 'Группы позиции сохранены.');
    }

    public function render(SupplyGroupService $service)
    {
        $term = trim($this->search);
        $suppliers = DB::table('purchase_supplier_regions')
            ->when($this->region === 'none', fn ($q) => $q->whereNull('region'))
            ->when(in_array($this->region, SupplyGroup::values(), true), fn ($q) => $q->where('region', $this->region))
            ->when($term !== '', fn ($q) => $q->where('supplier_name_1c', 'ilike', '%'.$term.'%'))
            ->orderByDesc('items_count')->orderBy('supplier_name_1c')
            ->paginate(50, ['*'], 'sp');

        $item = $this->itemId ? CatalogItem::query()->find($this->itemId, ['id', 'sku', 'name']) : null;
        $itemSource = $this->itemId ? DB::table('catalog_item_supply_groups')->where('catalog_item_id', $this->itemId)->value('source') : null;

        // Карточка поставщика в справочнике: по закупкам 1С с этим названием (самая частая привязка).
        $cards = DB::table('catalog_supplier_prices')->whereIn('supplier_name_1c', collect($suppliers->items())->pluck('supplier_name_1c'))
            ->whereNotNull('supplier_id')->selectRaw('supplier_name_1c, supplier_id, count(*) as c')->groupBy('supplier_name_1c', 'supplier_id')
            ->orderByDesc('c')->get()->unique('supplier_name_1c')->pluck('supplier_id', 'supplier_name_1c');

        return view('livewire.quoteparts.supply-groups', [
            'suppliers' => $suppliers,
            'cards' => $cards,
            'groupCounts' => $service->counts(),
            'regionCounts' => DB::table('purchase_supplier_regions')->selectRaw("coalesce(region, 'none') as r, count(*) as c")->groupBy('r')->pluck('c', 'r')->all(),
            'item' => $item,
            'itemSource' => $itemSource,
        ]);
    }
}
