<?php

namespace App\Livewire\Procurement;

use App\Enums\Role;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Раздел «Снабжение» (Фаза 4). Топ M-позиций, сдерживающих выдачу КП: позиции,
 * сматченные на каталог (M-артикул) с НЕАКТУАЛЬНОЙ ценой в заявках, не дошедших
 * до КП (new/assigned/in_progress/awaiting_client_clarification), агрегированные
 * по catalog_item с числом заблокированных заявок. Из него снабженец (Фаза 4.2)
 * формирует запросы поставщикам по M-артикулу. Доступ: procurement + manager +
 * head_of_sales/director/admin.
 */
class Index extends Component
{
    use WithPagination;

    /** Статусы «не дошло до КП». */
    public const PRE_QUOTE = ['new', 'assigned', 'in_progress', 'awaiting_client_clarification'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** cid => bool — выбранные позиции для запроса. */
    public array $selected = [];

    /** supplier_id => bool — кому слать. */
    public array $selectedSuppliers = [];

    /** Доп. поставщики, добавленные вручную (вне матча). */
    public array $addedSupplierIds = [];

    public string $supplierSearch = '';

    public string $greeting = 'Здравствуйте, {поставщик}!';

    public string $note = '';

    /** cid => отредактированное название для письма. */
    public array $editedNames = [];

    public function mount(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole([
                Role::Procurement->value, Role::Manager->value,
                Role::HeadOfSales->value, Role::Director->value, Role::Admin->value,
            ]),
            403,
        );
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /** При выборе позиции — префилл редактируемого названия. */
    public function updatedSelected($value, $key): void
    {
        if ($value && ! isset($this->editedNames[$key])) {
            $this->editedNames[$key] = (string) (\App\Models\CatalogItem::whereKey((int) $key)->value('name') ?? '');
        }
    }

    /** @return array<int, int> выбранные catalog_item_id */
    private function selectedCids(): array
    {
        return array_values(array_map('intval', array_keys(array_filter($this->selected))));
    }

    /** Выбранные позиции (для панели запроса). */
    #[Computed]
    public function selectedPositions()
    {
        $cids = $this->selectedCids();
        if ($cids === []) {
            return collect();
        }

        return \App\Models\CatalogItem::query()->whereIn('id', $cids)
            ->orderBy('sku')->get(['id', 'sku', 'name', 'brand', 'brand_article']);
    }

    /**
     * Подобранные поставщики под выбранные позиции (по матрице каталога) +
     * добавленные вручную.
     *
     * @return array<int, array{id:int, name:string, email:?string, matched:bool, item_count:int}>
     */
    #[Computed]
    public function supplierOptions(): array
    {
        $cids = $this->selectedCids();
        if ($cids === [] && $this->addedSupplierIds === []) {
            return [];
        }

        $matcher = app(\App\Services\Supplier\SupplierMatchService::class);
        $items = \App\Models\CatalogItem::query()->whereIn('id', $cids)
            ->with('equipmentCategory:id,name,synonyms')->get();

        $coverage = [];
        foreach ($items as $ci) {
            foreach ($matcher->relevantSuppliersForCatalog($ci) as $s) {
                $coverage[$s->id] = ($coverage[$s->id] ?? 0) + 1;
            }
        }

        $ids = array_values(array_unique(array_merge(array_keys($coverage), array_map('intval', $this->addedSupplierIds))));
        if ($ids === []) {
            return [];
        }
        $suppliers = \App\Models\Supplier::query()->whereIn('id', $ids)->get()->keyBy('id');

        $out = [];
        foreach ($ids as $id) {
            $s = $suppliers->get($id);
            if (! $s) {
                continue;
            }
            $out[] = [
                'id' => $s->id,
                'name' => (string) ($s->name ?: $s->email ?: ('#' . $s->id)),
                'email' => $s->email,
                'matched' => isset($coverage[$id]),
                'item_count' => $coverage[$id] ?? 0,
            ];
        }
        usort($out, fn ($a, $b) => $b['item_count'] <=> $a['item_count']);

        return $out;
    }

    #[Computed]
    public function searchResults()
    {
        $s = trim($this->supplierSearch);
        if (mb_strlen($s) < 2) {
            return collect();
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
        $existing = collect($this->supplierOptions())->pluck('id')->all();

        return \App\Models\Supplier::query()
            ->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('domain', 'ilike', $like))
            ->whereNotIn('id', $existing ?: [0])
            ->orderBy('name')->limit(8)->get(['id', 'name', 'email']);
    }

    public function addSupplier(int $supplierId): void
    {
        if (! in_array($supplierId, $this->addedSupplierIds, true)) {
            $this->addedSupplierIds[] = $supplierId;
        }
        $this->selectedSuppliers[$supplierId] = true;
        $this->supplierSearch = '';
        unset($this->supplierOptions);
    }

    public function send(\App\Services\Supplier\SupplierProcurementDispatchService $dispatcher)
    {
        $user = auth()->user();
        abort_unless($user?->hasAnyRole([
            Role::Procurement->value, Role::Manager->value,
            Role::HeadOfSales->value, Role::Director->value, Role::Admin->value,
        ]), 403);

        $cids = $this->selectedCids();
        $supplierIds = array_values(array_map('intval', array_keys(array_filter($this->selectedSuppliers))));
        if ($cids === []) {
            $this->addError('send', 'Выберите хотя бы одну позицию.');

            return;
        }
        if ($supplierIds === []) {
            $this->addError('send', 'Отметьте хотя бы одного поставщика.');

            return;
        }

        $result = $dispatcher->dispatch($cids, $supplierIds, $this->note, $user, $this->editedNames, $this->greeting);

        if (($result['error'] ?? null) === 'no_mailbox') {
            $this->addError('send', 'Нет ящика для отправки (личный или общий mail@). Обратитесь к РОПу.');

            return;
        }

        $msg = "Отправлено запросов поставщикам: {$result['sent']}.";
        if (($result['skipped'] ?? 0) > 0) {
            $msg .= " Пропущено (уже запрошено): {$result['skipped']}.";
        }
        if (($result['failed'] ?? 0) > 0) {
            $msg .= " Ошибок: {$result['failed']}.";
        }
        session()->flash('procurement_status', $msg);

        // Сброс выбора.
        $this->reset(['selected', 'selectedSuppliers', 'addedSupplierIds', 'supplierSearch', 'note', 'editedNames']);
        unset($this->positions, $this->supplierOptions, $this->iqotByCatalogId, $this->selectedPositions);
    }

    /** Базовый запрос блокеров (сматченные stale-позиции в до-КП заявках). */
    private function baseQuery(): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('request_items')
            ->join('catalog_items', 'catalog_items.id', '=', 'request_items.catalog_item_id')
            ->join('requests', 'requests.id', '=', 'request_items.request_id')
            ->where('request_items.is_active', true)
            ->whereNotNull('request_items.catalog_item_id')
            ->where('catalog_items.is_price_actual', false)
            ->whereIn('requests.status', self::PRE_QUOTE)
            ->whereNull('requests.merged_into_id');

        $s = trim($this->search);
        if ($s !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('catalog_items.sku', 'ilike', $like)
                    ->orWhere('catalog_items.name', 'ilike', $like)
                    ->orWhere('catalog_items.brand', 'ilike', $like);
            });
        }

        return $q;
    }

    /** @return array{positions:int, requests:int} */
    #[Computed]
    public function summary(): array
    {
        return [
            'positions' => (clone $this->baseQuery())->distinct()->count('catalog_items.id'),
            'requests' => (clone $this->baseQuery())->distinct()->count('requests.id'),
        ];
    }

    /**
     * Топ позиций-блокеров. Агрегируем по catalog_item, обогащаем страницу
     * кодами заявок и флагом «уже в работе» (есть pending-запрос поставщику).
     */
    #[Computed]
    public function positions(): LengthAwarePaginator
    {
        $rows = (clone $this->baseQuery())
            ->select(
                'catalog_items.id as cid',
                'catalog_items.sku',
                'catalog_items.name',
                'catalog_items.brand',
                'catalog_items.price',
                DB::raw('count(distinct requests.id) as req_count'),
            )
            ->groupBy('catalog_items.id', 'catalog_items.sku', 'catalog_items.name', 'catalog_items.brand', 'catalog_items.price')
            ->orderByDesc('req_count')
            ->orderBy('catalog_items.sku')
            ->get();

        $perPage = 25;
        $page = Paginator::resolveCurrentPage();
        $slice = $rows->slice(($page - 1) * $perPage, $perPage)->values();
        $cids = $slice->pluck('cid')->all();

        // Коды заблокированных заявок по позиции (первые несколько).
        $codes = [];
        if ($cids !== []) {
            $codeRows = DB::table('request_items')
                ->join('requests', 'requests.id', '=', 'request_items.request_id')
                ->where('request_items.is_active', true)
                ->whereIn('request_items.catalog_item_id', $cids)
                ->whereIn('requests.status', self::PRE_QUOTE)
                ->whereNull('requests.merged_into_id')
                ->select('request_items.catalog_item_id as cid', 'requests.id', 'requests.internal_code')
                ->distinct()
                ->get();
            foreach ($codeRows->groupBy('cid') as $cid => $grp) {
                $codes[$cid] = $grp->unique('id')->take(8)
                    ->map(fn ($r) => ['id' => $r->id, 'code' => $r->internal_code])
                    ->values()->all();
            }
        }

        // «В работе» — по позиции есть pending supplier_inquiry_item.
        $inFlight = [];
        if ($cids !== []) {
            $inFlight = DB::table('supplier_inquiry_items')
                ->join('request_items', 'request_items.id', '=', 'supplier_inquiry_items.request_item_id')
                ->where('supplier_inquiry_items.status', 'pending')
                ->whereIn('request_items.catalog_item_id', $cids)
                ->distinct()
                ->pluck('request_items.catalog_item_id')
                ->all();
        }
        $inFlight = array_flip($inFlight);

        $enriched = $slice->map(fn ($r) => [
            'cid' => $r->cid,
            'sku' => $r->sku,
            'name' => $r->name,
            'brand' => $r->brand,
            'price' => $r->price,
            'req_count' => (int) $r->req_count,
            'codes' => $codes[$r->cid] ?? [],
            'in_flight' => isset($inFlight[$r->cid]),
        ])->all();

        return new LengthAwarePaginator(
            $enriched,
            $rows->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    /**
     * Карта catalog_item_id → IqotPosition (анализ цен конкурентов) для позиций
     * текущей страницы. Та же выборка, что в разделе «Аналитика → Позиции»
     * (analyzed + report), чтобы вывод цен был единообразным (зелёный чип +
     * раскрывающееся сравнение livewire.iqot._comparison).
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\IqotPosition>
     */
    #[Computed]
    public function iqotByCatalogId()
    {
        $ids = collect($this->positions->items())->pluck('cid')->filter()->all();
        if ($ids === []) {
            return collect();
        }

        return \App\Models\IqotPosition::with('catalogItem:id,sku,name,brand,brand_article,brands,articles,price,price_min,is_price_actual,lead_time_days')
            ->whereIn('catalog_item_id', $ids)
            ->whereNotNull('analyzed_at')
            ->whereNotNull('report')
            ->get()
            ->keyBy('catalog_item_id');
    }

    public function render()
    {
        return view('livewire.procurement.index');
    }
}
