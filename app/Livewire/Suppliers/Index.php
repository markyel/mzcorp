<?php

namespace App\Livewire\Suppliers;

use App\Models\SupplierInquiry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Раздел «Поставщики» — список запросов расценки поставщикам (SupplierInquiry).
 * Тред, помеченный как наш запрос поставщику; ответы в нём — переписка, не
 * клиентские заявки. Доступ — все роли (как «Клиенты»).
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
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

    public function render()
    {
        return view('livewire.suppliers.index');
    }
}
