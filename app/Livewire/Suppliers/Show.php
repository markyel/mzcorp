<?php

namespace App\Livewire\Suppliers;

use App\Models\SupplierInquiry;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Карточка запроса поставщику: реквизиты поставщика, статус, связанная
 * клиентская заявка (если есть), заметки и тред переписки. Доступ — все роли.
 */
class Show extends Component
{
    public SupplierInquiry $inquiry;

    public string $supplier_name = '';
    public string $notes = '';

    public function mount(SupplierInquiry $inquiry): void
    {
        abort_unless(auth()->check(), 403);
        $this->inquiry = $inquiry;
        $this->supplier_name = (string) ($inquiry->supplier_name ?? '');
        $this->notes = (string) ($inquiry->notes ?? '');
    }

    public function save(): void
    {
        $this->validate([
            'supplier_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
        ], [], ['supplier_name' => 'название']);

        $this->inquiry->update([
            'supplier_name' => trim($this->supplier_name) !== '' ? trim($this->supplier_name) : null,
            'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
        ]);

        $this->dispatch('toast', message: 'Сохранено.', type: 'success');
    }

    public function toggleStatus(): void
    {
        $this->inquiry->update([
            'status' => $this->inquiry->status === 'closed' ? 'open' : 'closed',
        ]);
        $this->dispatch('toast', message: 'Статус запроса обновлён.', type: 'success');
    }

    public function deleteInquiry()
    {
        // nullOnDelete: письма открепляются (supplier_inquiry_id → null),
        // сами письма и их category=supplier_reply остаются.
        $this->inquiry->delete();

        return $this->redirectRoute('suppliers.index', navigate: true);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\EmailMessage>
     */
    #[Computed]
    public function messages()
    {
        return $this->inquiry->messages()
            ->get(['id', 'direction', 'from_email', 'from_name', 'subject', 'sent_at', 'body_plain', 'related_request_id']);
    }

    /**
     * Запрошенные позиции + предложения поставщика по ним (Фаза 3.3).
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\SupplierInquiryItem>
     */
    #[Computed]
    public function inquiryItems()
    {
        return $this->inquiry->items()
            ->with(['requestItem:id,parsed_name,parsed_article', 'offers'])
            ->get();
    }

    public function render()
    {
        return view('livewire.suppliers.show');
    }
}
