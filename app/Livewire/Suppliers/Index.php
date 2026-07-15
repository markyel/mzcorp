<?php

namespace App\Livewire\Suppliers;

use App\Models\RequestItem;
use App\Models\Supplier;
use App\Models\SupplierInquiry;
use App\Models\SupplierInquiryItem;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Раздел «Поставщики» — две вкладки: «Запросы» (SupplierInquiry — треды наших
 * запросов расценки) и «Реестр» (список email/доменов поставщиков, гейт для
 * send-time распознавания исходящих RFQ). Доступ — все роли (как «Клиенты»).
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'tab', except: 'inquiries')]
    public string $tab = 'inquiries';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /* --- Добавление поставщика в реестр --- */
    public string $newEmail = '';
    public string $newDomain = '';
    public string $newName = '';

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    public function setTab(string $t): void
    {
        $this->tab = in_array($t, ['inquiries', 'registry', 'nomenclature'], true) ? $t : 'inquiries';
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function addSupplier(): void
    {
        $this->validate([
            'newEmail' => 'nullable|email|max:255',
            'newDomain' => 'nullable|string|max:255',
            'newName' => 'nullable|string|max:255',
        ], [], ['newEmail' => 'email', 'newDomain' => 'домен', 'newName' => 'название']);

        $email = mb_strtolower(trim($this->newEmail));
        $domain = mb_strtolower(trim($this->newDomain));
        $domain = ltrim($domain, '@');
        if ($email === '' && $domain === '') {
            $this->addError('newEmail', 'Укажите email или домен.');

            return;
        }

        Supplier::create([
            'email' => $email !== '' ? $email : null,
            'domain' => $domain !== '' ? $domain : null,
            'name' => trim($this->newName) !== '' ? trim($this->newName) : null,
            'created_by_user_id' => auth()->id(),
        ]);

        $this->newEmail = '';
        $this->newDomain = '';
        $this->newName = '';
        unset($this->suppliers);
        $this->dispatch('toast', message: 'Поставщик добавлен в реестр.', type: 'success');
    }

    public function removeSupplier(int $id): void
    {
        Supplier::whereKey($id)->delete();
        unset($this->suppliers);
        $this->dispatch('toast', message: 'Удалён из реестра.', type: 'success');
    }

    #[Computed]
    public function inquiries()
    {
        $q = SupplierInquiry::query()
            ->withCount(['messages', 'inboundMessages', 'items'])
            ->with('createdBy:id,name');

        $s = trim($this->search);
        if ($s !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('supplier_email', 'ilike', $like)
                    ->orWhere('supplier_name', 'ilike', $like)
                    ->orWhere('subject', 'ilike', $like);
            });
        }

        return $q->orderByDesc('id')->paginate(30);
    }

    #[Computed]
    public function suppliers()
    {
        $q = Supplier::query()->with('createdBy:id,name');

        $s = trim($this->search);
        if ($s !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('email', 'ilike', $like)
                    ->orWhere('domain', 'ilike', $like)
                    ->orWhere('name', 'ilike', $like);
            });
        }

        return $q->orderBy('email')->orderBy('domain')->paginate(40);
    }

    /**
     * Номенклатура: позиции, по которым запрашивали цену, + предложения
     * поставщиков по каждой (Фаза 3.3). Группируем по RequestItem; сюда же
     * подмешиваются позиция-центричные RFQ из «Снабжения» (supplier_inquiry_items
     * по catalog_item_id без request_item_id) — по тому же ключу c{catalog_item_id}.
     */
    /**
     * Единая валюта среди quoted-офферов позиции (пустая у оффера → ₽).
     * null — офферы в РАЗНЫХ валютах: единый числовой диапазон по ним
     * некорректен, поэтому диапазон в UI не показываем.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\SupplierOffer>  $quoted
     */
    private function quotedCurrency(\Illuminate\Support\Collection $quoted): ?string
    {
        $currencies = $quoted
            ->map(fn ($o) => trim((string) $o->currency) !== '' ? trim((string) $o->currency) : '₽')
            ->unique()
            ->values();

        return $currencies->count() === 1 ? (string) $currencies->first() : null;
    }

    #[Computed]
    public function positions()
    {
        $requestedIds = SupplierInquiryItem::query()
            ->whereNotNull('request_item_id')->select('request_item_id');

        $q = RequestItem::query()
            ->whereIn('id', $requestedIds)
            ->with([
                'request:id,internal_code',
                'brand:id,name',
                'catalogItem:id,sku,name,is_price_actual',
                'supplierInquiryItems.inquiry:id,supplier_email,supplier_name',
                'supplierInquiryItems.offers',
            ]);

        $s = trim($this->search);
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
        if ($s !== '') {
            $q->where(function ($w) use ($like) {
                $w->where('parsed_name', 'ilike', $like)
                    ->orWhere('parsed_article', 'ilike', $like)
                    ->orWhereHas('catalogItem', fn ($c) => $c->where('name', 'ilike', $like)->orWhere('sku', 'ilike', $like));
            });
        }

        // Схлопываем по catalog SKU: одна номенклатура из разных заявок = одна
        // строка с офферами от всех поставщиков. Некаталожные (без SKU) — каждая
        // отдельно (по своему id). Группировка в PHP → ручная пагинация.
        $groups = $q->orderByDesc('id')->get()
            ->groupBy(fn (RequestItem $it) => $it->catalog_item_id ? 'c' . $it->catalog_item_id : 'r' . $it->id)
            ->map(function ($rows) {
                /** @var \App\Models\RequestItem $first */
                $first = $rows->first();
                $isCatalog = (bool) $first->catalog_item_id;
                $name = $isCatalog ? ($first->catalogItem?->name ?: $first->parsed_name) : $first->parsed_name;
                $siis = $rows->flatMap->supplierInquiryItems;
                $quoted = $siis->flatMap->offers->where('outcome', 'quoted')->filter(fn ($o) => $o->price !== null);

                return [
                    'key' => $isCatalog ? 'c' . $first->catalog_item_id : 'r' . $first->id,
                    'name' => (string) ($name ?: '—'),
                    'sku' => $isCatalog ? $first->catalogItem?->sku : null,
                    'article' => $first->parsed_article,
                    'is_catalog' => $isCatalog,
                    'stale' => $isCatalog && $first->catalogItem && ! $first->catalogItem->is_price_actual,
                    'requests' => $rows->map(fn ($r) => ['id' => $r->request_id, 'code' => $r->request?->internal_code])
                        ->unique('id')->values(),
                    'siis' => $siis,
                    'min' => $quoted->min('price'),
                    'max' => $quoted->max('price'),
                    'quoted_count' => $quoted->count(),
                    'currency' => $this->quotedCurrency($quoted),
                ];
            });

        // Позиция-центричные RFQ из «Снабжения»: catalog_item_id без request_item_id.
        $catSiis = SupplierInquiryItem::query()
            ->whereNotNull('catalog_item_id')
            ->with([
                'inquiry:id,supplier_email,supplier_name',
                'offers',
                'catalogItem:id,sku,name,is_price_actual',
            ])
            ->when($s !== '', fn ($w) => $w->whereHas(
                'catalogItem',
                fn ($c) => $c->where('name', 'ilike', $like)->orWhere('sku', 'ilike', $like),
            ))
            ->get()
            ->groupBy(fn (SupplierInquiryItem $sii) => 'c' . $sii->catalog_item_id);

        foreach ($catSiis as $key => $siis) {
            $existing = $groups->get($key);
            if ($existing !== null) {
                $all = $existing['siis']->concat($siis)->unique('id')->values();
                $quoted = $all->flatMap->offers->where('outcome', 'quoted')->filter(fn ($o) => $o->price !== null);
                $existing['siis'] = $all;
                $existing['min'] = $quoted->min('price');
                $existing['max'] = $quoted->max('price');
                $existing['quoted_count'] = $quoted->count();
                $existing['currency'] = $this->quotedCurrency($quoted);
                $groups->put($key, $existing);

                continue;
            }
            $ci = $siis->first()->catalogItem;
            $quoted = $siis->flatMap->offers->where('outcome', 'quoted')->filter(fn ($o) => $o->price !== null);
            $groups->put($key, [
                'key' => $key,
                'name' => (string) ($ci?->name ?: $siis->first()->item_name ?: '—'),
                'sku' => $ci?->sku,
                'article' => null,
                'is_catalog' => true,
                'stale' => $ci !== null && ! $ci->is_price_actual,
                'requests' => collect(),
                'siis' => $siis,
                'min' => $quoted->min('price'),
                'max' => $quoted->max('price'),
                'quoted_count' => $quoted->count(),
                'currency' => $this->quotedCurrency($quoted),
            ]);
        }

        $groups = $groups->values();

        $perPage = 25;
        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $groups->slice(($page - 1) * $perPage, $perPage)->values(),
            $groups->count(),
            $perPage,
            $page,
            ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()],
        );
    }

    public function render()
    {
        return view('livewire.suppliers.index');
    }
}
