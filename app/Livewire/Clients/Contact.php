<?php

namespace App\Livewire\Clients;

use App\Enums\InvoiceStatus;
use App\Enums\RequestStatus;
use App\Models\ClientContact;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Request as RequestModel;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Карточка контакта (e-mail заказчика): ФИО / телефон, связанные организации,
 * накопленная история (заявки / КП / счета) и статистика по этому email.
 * Доступ и редактирование — все роли.
 */
class Contact extends Component
{
    public ClientContact $contact;

    public string $full_name = '';
    public string $phone = '';
    public string $notes = '';

    public function mount(ClientContact $contact): void
    {
        abort_unless(auth()->check(), 403);
        $this->contact = $contact;
        $this->full_name = (string) ($contact->full_name ?? '');
        $this->phone = (string) ($contact->phone ?? '');
        $this->notes = (string) ($contact->notes ?? '');
    }

    public function save(): void
    {
        $this->validate([
            'full_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:5000',
        ], [], ['full_name' => 'ФИО', 'phone' => 'телефон']);

        $this->contact->update([
            'full_name' => trim($this->full_name) !== '' ? trim($this->full_name) : null,
            'phone' => trim($this->phone) !== '' ? trim($this->phone) : null,
            'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
        ]);

        $this->dispatch('toast', message: 'Контакт сохранён.', type: 'success');
    }

    private function email(): string
    {
        return mb_strtolower((string) $this->contact->email);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Organization>
     */
    #[Computed]
    public function organizations()
    {
        return $this->contact->organizations()->get();
    }

    /**
     * @return array{requests:int, won:int, lost:int, active:int, quotations:int, invoices:int, paid:int}
     */
    #[Computed]
    public function stats(): array
    {
        $email = $this->email();
        $base = RequestModel::query()->whereRaw('lower(client_email) = ?', [$email]);
        $requests = (clone $base)->count();
        $won = (clone $base)->where('status', RequestStatus::ClosedWon->value)->count();
        $lost = (clone $base)->where('status', RequestStatus::ClosedLost->value)->count();

        $byEmail = fn ($r) => $r->whereRaw('lower(client_email) = ?', [$email]);
        $quotations = Quotation::whereHas('request', $byEmail)->count();
        $invBase = Invoice::whereHas('request', $byEmail);
        $invoices = (clone $invBase)->count();
        $paid = (clone $invBase)->where('status', InvoiceStatus::Paid->value)->count();

        return [
            'requests' => $requests,
            'won' => $won,
            'lost' => $lost,
            'active' => max(0, $requests - $won - $lost),
            'quotations' => $quotations,
            'invoices' => $invoices,
            'paid' => $paid,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, RequestModel>
     */
    #[Computed]
    public function recentRequests()
    {
        return RequestModel::query()
            ->whereRaw('lower(client_email) = ?', [$this->email()])
            ->with('assignedUser:id,name')
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'internal_code', 'status', 'subject', 'assigned_user_id', 'created_at']);
    }

    public function render()
    {
        return view('livewire.clients.contact');
    }
}
