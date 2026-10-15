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

    /** Содержимое группы: cn / ru / eu, none — позиции без группы, '' — список закрыт. */
    #[Url(as: 'group', except: '')]
    public string $group = '';

    #[Url(as: 'gq', except: '')]
    public string $groupSearch = '';

    /** M-артикул позиции, чьи группы правим руками. */
    public string $sku = '';

    /** @var list<string> */
    public array $itemGroups = [];

    public ?int $itemId = null;

    /** Строка поставщика 1С, которую привязываем к карточке справочника, и поиск карточки. */
    public ?int $linkingId = null;

    public string $linkSearch = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['director', 'admin']), 403);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['region', 'search'], true)) {
            $this->resetPage();
        }
        if (in_array($name, ['group', 'groupSearch'], true)) {
            $this->resetPage('ip');
        }
    }

    public function setRegion(int $id, string $region, SupplyGroupService $service): void
    {
        $service->setRegion($id, $region === '' ? null : $region, auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Регион сохранён. Группы позиций обновятся после «Пересчитать».');
    }

    /** «Привязать к карточке»: поиск по справочнику начинается с названия 1С до скобок. */
    public function startLink(int $regionId): void
    {
        $name = (string) DB::table('purchase_supplier_regions')->where('id', $regionId)->value('supplier_name_1c');
        $this->linkingId = $regionId;
        $this->linkSearch = trim((string) preg_split('/[(\[]/u', $name)[0]);
    }

    public function cancelLink(): void
    {
        $this->linkingId = null;
        $this->linkSearch = '';
    }

    public function linkTo(int $supplierId, SupplyGroupService $service): void
    {
        abort_if($this->linkingId === null, 404);
        $count = $service->linkSupplier($this->linkingId, $supplierId, auth()->user());
        $this->cancelLink();
        $this->dispatch('toast', type: 'success', message: 'Привязано: закупок '.$count.'. Название в выгрузке цен и регион при приглашении берутся теперь из этой карточки.');
    }

    public function rebuild(SupplyGroupService $service): void
    {
        $service->refreshRegions();
        $counts = $service->rebuild();
        // Позиции пула уйдут в QuoteParts с новыми группами (изменилась карточка — повторная отправка).
        SyncQuotePartsPoolJob::dispatch();
        $this->dispatch('toast', type: 'success', message: 'Пересчитано: '.collect(SupplyGroup::cases())->map(fn ($g) => $g->label().' '.($counts[$g->value] ?? 0))->implode(', ').'.');
    }

    /** «изменить» в списке группы — та же правка групп позиции руками. */
    public function editItem(string $sku, SupplyGroupService $service): void
    {
        $this->sku = $sku;
        $this->findItem($service);
        $this->js("document.getElementById('item-groups')?.scrollIntoView({behavior: 'smooth', block: 'center'})");
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

        // Карточка поставщика в справочнике: привязанная руками, иначе по закупкам 1С с этим названием (самая частая).
        $cards = DB::table('catalog_supplier_prices')->whereIn('supplier_name_1c', collect($suppliers->items())->pluck('supplier_name_1c'))
            ->whereNotNull('supplier_id')->selectRaw('supplier_name_1c, supplier_id, count(*) as c')->groupBy('supplier_name_1c', 'supplier_id')
            ->orderByDesc('c')->get()->unique('supplier_name_1c')->pluck('supplier_id', 'supplier_name_1c')
            ->merge(collect($suppliers->items())->whereNotNull('supplier_id')->pluck('supplier_id', 'supplier_name_1c'));
        $cardNames = \App\Models\Supplier::query()->with('organization:id,name')->whereIn('id', $cards->values())->get(['id', 'name', 'email', 'supplier_organization_id'])->keyBy('id');
        $term = trim($this->linkSearch);
        $candidates = $this->linkingId === null || mb_strlen($term) < 2 ? collect() : \App\Models\Supplier::query()->with('organization:id,name')
            ->where(fn ($q) => $q->where('name', 'ilike', '%'.$term.'%')->orWhere('email', 'ilike', '%'.$term.'%')->orWhere('domain', 'ilike', '%'.$term.'%')
                ->orWhereHas('organization', fn ($o) => $o->where('name', 'ilike', '%'.$term.'%')))
            ->orderBy('name')->limit(12)->get(['id', 'name', 'email', 'supplier_organization_id']);

        // Содержимое выбранной группы: позиции, их группы и поставщики 1С (с регионом).
        $groupItems = null;
        $itemSuppliers = collect();
        $itemGroupsMap = [];
        if ($this->group !== '') {
            $gterm = trim($this->groupSearch);
            $groupItems = CatalogItem::query()
                ->when($this->group === 'none',
                    fn ($q) => $q->whereNotExists(fn ($s) => $s->selectRaw('1')->from('catalog_item_supply_groups as g')->whereColumn('g.catalog_item_id', 'catalog_items.id')),
                    fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('catalog_item_supply_groups as g')->whereColumn('g.catalog_item_id', 'catalog_items.id')->where('g.group_code', $this->group)))
                ->when($gterm !== '', fn ($q) => $q->where(fn ($w) => $w->where('sku', 'ilike', '%'.$gterm.'%')->orWhere('name', 'ilike', '%'.$gterm.'%')->orWhere('brand', 'ilike', '%'.$gterm.'%')->orWhere('brand_article', 'ilike', '%'.$gterm.'%')))
                ->orderBy('sku')
                ->paginate(50, ['id', 'sku', 'name', 'brand', 'brand_article'], 'ip');
            $ids = $groupItems->getCollection()->pluck('id')->all();
            $itemGroupsMap = DB::table('catalog_item_supply_groups')->whereIn('catalog_item_id', $ids)->get(['catalog_item_id', 'group_code', 'source'])->groupBy('catalog_item_id')->all();
            $itemSuppliers = DB::table('catalog_supplier_prices as p')->leftJoin('purchase_supplier_regions as r', 'r.supplier_name_1c', '=', 'p.supplier_name_1c')
                ->whereIn('p.catalog_item_id', $ids)->select('p.catalog_item_id', 'p.supplier_name_1c', 'r.region')->distinct()->get()->groupBy('catalog_item_id');
        }

        return view('livewire.quoteparts.supply-groups', [
            'groupItems' => $groupItems,
            'itemSuppliers' => $itemSuppliers,
            'itemGroupsMap' => $itemGroupsMap,
            'noGroupCount' => $this->group === 'none' && $groupItems ? $groupItems->total() : null,
            'suppliers' => $suppliers,
            'cards' => $cards,
            'cardNames' => $cardNames,
            'candidates' => $candidates,
            'groupCounts' => $service->counts(),
            'regionCounts' => DB::table('purchase_supplier_regions')->selectRaw("coalesce(region, 'none') as r, count(*) as c")->groupBy('r')->pluck('c', 'r')->all(),
            'item' => $item,
            'itemSource' => $itemSource,
        ]);
    }
}
