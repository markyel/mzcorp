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
            ->withCount('messages')
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
     * поставщиков по каждой (Фаза 3.3). Группируем по RequestItem.
     */
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
        if ($s !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('parsed_name', 'ilike', $like)
                    ->orWhere('parsed_article', 'ilike', $like)
                    ->orWhereHas('catalogItem', fn ($c) => $c->where('name', 'ilike', $like)->orWhere('sku', 'ilike', $like));
            });
        }

        return $q->orderByDesc('id')->paginate(25);
    }

    public function render()
    {
        return view('livewire.suppliers.index');
    }
}
