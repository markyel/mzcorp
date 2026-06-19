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

    public function render()
    {
        return view('livewire.procurement.index');
    }
}
