<?php

namespace App\Livewire\Clients;

use App\Enums\InvoiceStatus;
use App\Enums\RequestStatus;
use App\Models\ClientContact;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Request as RequestModel;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Карточка организации (раздел «Клиенты»): реквизиты + скидка, контакты
 * (ФИО / телефон / email, M:N), базовая статистика по заявкам/КП/счетам.
 * Доступ и редактирование — все роли.
 */
class Show extends Component
{
    public Organization $organization;

    /* --- Реквизиты организации --- */
    public string $name = '';
    public string $inn = '';
    public string $kpp = '';
    public string $address = '';
    public string $requisites_text = '';
    public string $discount_percent = '0';
    public string $notes = '';

    /* --- Добавление контакта --- */
    public string $newContactEmail = '';
    public string $newContactName = '';
    public string $newContactPhone = '';

    /* --- Инлайн-редактирование контакта --- */
    public ?int $editingContactId = null;
    public string $editName = '';
    public string $editPhone = '';

    public bool $confirmingDelete = false;

    public function mount(Organization $organization): void
    {
        abort_unless(auth()->check(), 403);
        $this->organization = $organization;
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $o = $this->organization;
        $this->name = (string) $o->name;
        $this->inn = (string) ($o->inn ?? '');
        $this->kpp = (string) ($o->kpp ?? '');
        $this->address = (string) ($o->address ?? '');
        $this->requisites_text = (string) ($o->requisites_text ?? '');
        $this->discount_percent = rtrim(rtrim((string) ($o->discount_percent ?? '0'), '0'), '.') ?: '0';
        $this->notes = (string) ($o->notes ?? '');
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'inn' => 'nullable|string|max:20',
            'kpp' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:1000',
            'requisites_text' => 'nullable|string|max:5000',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string|max:5000',
        ], [], [
            'name' => 'название',
            'inn' => 'ИНН',
            'kpp' => 'КПП',
            'discount_percent' => 'скидка',
        ]);

        $this->organization->update([
            'name' => trim($this->name),
            'inn' => trim($this->inn) !== '' ? trim($this->inn) : null,
            'kpp' => trim($this->kpp) !== '' ? trim($this->kpp) : null,
            'address' => trim($this->address) !== '' ? trim($this->address) : null,
            'requisites_text' => trim($this->requisites_text) !== '' ? trim($this->requisites_text) : null,
            'discount_percent' => (float) ($this->discount_percent !== '' ? $this->discount_percent : 0),
            'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
        ]);

        $this->dispatch('toast', message: 'Реквизиты сохранены.', type: 'success');
    }

    public function addContact(): void
    {
        $this->validate([
            'newContactEmail' => 'required|email|max:255',
            'newContactName' => 'nullable|string|max:255',
            'newContactPhone' => 'nullable|string|max:64',
        ], [], [
            'newContactEmail' => 'email',
            'newContactName' => 'ФИО',
            'newContactPhone' => 'телефон',
        ]);

        $email = mb_strtolower(trim($this->newContactEmail));
        $contact = ClientContact::firstOrCreate(['email' => $email]);
        if (trim((string) $contact->full_name) === '' && trim($this->newContactName) !== '') {
            $contact->full_name = trim($this->newContactName);
        }
        if (trim((string) $contact->phone) === '' && trim($this->newContactPhone) !== '') {
            $contact->phone = trim($this->newContactPhone);
        }
        $contact->save();

        if (! $this->organization->contacts()->where('client_contacts.id', $contact->id)->exists()) {
            $this->organization->contacts()->attach($contact->id);
        }

        $this->newContactEmail = '';
        $this->newContactName = '';
        $this->newContactPhone = '';
        unset($this->contacts);
        $this->dispatch('toast', message: 'Контакт добавлен.', type: 'success');
    }

    public function startEditContact(int $contactId): void
    {
        $contact = $this->organization->contacts()->where('client_contacts.id', $contactId)->first();
        if (! $contact) {
            return;
        }
        $this->editingContactId = $contactId;
        $this->editName = (string) ($contact->full_name ?? '');
        $this->editPhone = (string) ($contact->phone ?? '');
    }

    public function cancelEditContact(): void
    {
        $this->editingContactId = null;
    }

    public function saveContact(): void
    {
        if (! $this->editingContactId) {
            return;
        }
        $this->validate([
            'editName' => 'nullable|string|max:255',
            'editPhone' => 'nullable|string|max:64',
        ]);
        $contact = ClientContact::find($this->editingContactId);
        if ($contact) {
            $contact->update([
                'full_name' => trim($this->editName) !== '' ? trim($this->editName) : null,
                'phone' => trim($this->editPhone) !== '' ? trim($this->editPhone) : null,
            ]);
        }
        $this->editingContactId = null;
        unset($this->contacts);
        $this->dispatch('toast', message: 'Контакт обновлён.', type: 'success');
    }

    public function detachContact(int $contactId): void
    {
        $this->organization->contacts()->detach($contactId);
        unset($this->contacts);
        $this->dispatch('toast', message: 'Контакт отвязан от организации.', type: 'success');
    }

    public function deleteOrganization()
    {
        $this->organization->delete();

        return $this->redirectRoute('clients.index', navigate: true);
    }

    /**
     * @return \Illuminate\Support\Collection<int, ClientContact>
     */
    #[Computed]
    public function contacts()
    {
        return $this->organization->contacts()->get();
    }

    /**
     * Статистика по email'ам организации (заявки / КП / счета).
     *
     * @return array{requests:int, won:int, lost:int, active:int, quotations:int, invoices:int, paid:int}
     */
    #[Computed]
    public function stats(): array
    {
        $emails = $this->organization->contactEmails();
        if ($emails === []) {
            return ['requests' => 0, 'won' => 0, 'lost' => 0, 'active' => 0, 'quotations' => 0, 'invoices' => 0, 'paid' => 0];
        }

        $reqBase = RequestModel::query()->whereIn(DB::raw('lower(client_email)'), $emails);
        $requests = (clone $reqBase)->count();
        $won = (clone $reqBase)->where('status', RequestStatus::ClosedWon->value)->count();
        $lost = (clone $reqBase)->where('status', RequestStatus::ClosedLost->value)->count();

        $byEmail = fn ($r) => $r->whereIn(DB::raw('lower(client_email)'), $emails);
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

    public function render()
    {
        return view('livewire.clients.show');
    }
}
