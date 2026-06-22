<?php

namespace App\Livewire\Clients;

use App\Enums\ClientNotificationType;
use App\Enums\InvoiceStatus;
use App\Enums\RequestStatus;
use App\Models\ClientContact;
use App\Models\ClientNotificationOptout;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Request as RequestModel;
use App\Services\Clients\RequestOrganizationResolver;
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

    /** Контакт, у которого раскрыта панель «Автоуведомления» (id), либо null. */
    public ?int $openNotifContactId = null;

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

        // Появилась связь email↔организация — подтянуть к ней ещё не
        // привязанные заявки этого email (точная привязка organization_id).
        $linked = app(RequestOrganizationResolver::class)
            ->backfillForEmailLink($this->organization, $email);

        $this->newContactEmail = '';
        $this->newContactName = '';
        $this->newContactPhone = '';
        unset($this->contacts);
        unset($this->stats);
        unset($this->recentRequests);
        $msg = $linked > 0
            ? "Контакт добавлен. Привязано заявок: {$linked}."
            : 'Контакт добавлен.';
        $this->dispatch('toast', message: $msg, type: 'success');
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
        unset($this->stats);
        unset($this->recentRequests);
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

    /* ------------- Персональные настройки автоуведомлений (по e-mail) -------- */

    /** Раскрыть/свернуть панель автоуведомлений у контакта. */
    public function toggleNotifPanel(int $contactId): void
    {
        $this->openNotifContactId = $this->openNotifContactId === $contactId ? null : $contactId;
    }

    /** Все типы автоуведомлений (для тумблеров). @return array<int, ClientNotificationType> */
    #[Computed]
    public function notificationTypes(): array
    {
        return ClientNotificationType::cases();
    }

    /**
     * Заглушённые типы по e-mail контактов организации (стоп-лист единый с
     * админ-страницей /dashboard/notification-optouts и гардом в
     * ClientNotificationService::dispatch).
     *
     * @return array<string, array<int, string>> lower(email) => [type_value, ...]
     */
    #[Computed]
    public function notificationOptouts(): array
    {
        $emails = $this->organization->contactEmails();
        if ($emails === []) {
            return [];
        }

        return ClientNotificationOptout::query()
            ->whereIn(DB::raw('lower(email)'), $emails)
            ->get(['email', 'suppressed_types'])
            ->mapWithKeys(fn (ClientNotificationOptout $e) => [
                mb_strtolower((string) $e->email) => array_values((array) $e->suppressed_types),
            ])
            ->all();
    }

    /**
     * Включить ↔ заглушить один тип автоуведомления для e-mail контакта.
     * Пишет в client_notification_optouts (suppressed_types). Пустую запись без
     * комментария удаляем (нет опт-аутов = всё включено по умолчанию).
     */
    public function toggleContactNotification(string $email, string $typeValue): void
    {
        abort_unless(auth()->check(), 403);
        if (ClientNotificationType::tryFrom($typeValue) === null) {
            return;
        }
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return;
        }

        $entry = ClientNotificationOptout::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->first() ?? new ClientNotificationOptout(['email' => $email]);

        $suppressed = array_values((array) ($entry->suppressed_types ?? []));
        if (in_array($typeValue, $suppressed, true)) {
            // было заглушено → включаем (убираем из списка).
            $suppressed = array_values(array_diff($suppressed, [$typeValue]));
        } else {
            // было включено → заглушаем.
            $suppressed[] = $typeValue;
        }

        $hasComment = trim((string) ($entry->comment ?? '')) !== '';
        if ($suppressed === [] && ! $hasComment) {
            if ($entry->exists) {
                $entry->delete();
            }
        } else {
            if (! $entry->exists) {
                $entry->created_by_user_id = auth()->id();
            }
            $entry->suppressed_types = $suppressed;
            $entry->save();
        }

        unset($this->notificationOptouts);
    }

    /**
     * Точная привязка заявки к организации: organization_id = эта орг. ИЛИ
     * (ещё не привязана И её client_email ∈ контакты организации). Второй
     * терм — fallback для заявок, до которых ещё не доехал бэкфилл; он не
     * учитывает заявки, явно привязанные к ДРУГОЙ организации (тот же email
     * у нескольких орг.), поэтому двойного счёта нет.
     *
     * @param  array<int, string>  $emails  email'ы контактов в нижнем регистре
     */
    private function requestScope(array $emails): \Closure
    {
        $orgId = $this->organization->id;

        return function ($q) use ($orgId, $emails) {
            $q->where('organization_id', $orgId);
            if ($emails !== []) {
                $q->orWhere(function ($e) use ($emails) {
                    $e->whereNull('organization_id')
                        ->whereIn(DB::raw('lower(client_email)'), $emails);
                });
            }
        };
    }

    /**
     * Статистика по заявкам организации (точная привязка + fallback по email).
     *
     * @return array{requests:int, won:int, lost:int, active:int, quotations:int, invoices:int, paid:int}
     */
    #[Computed]
    public function stats(): array
    {
        $scope = $this->requestScope($this->organization->contactEmails());

        $reqBase = RequestModel::query()->where($scope);
        $requests = (clone $reqBase)->count();
        $won = (clone $reqBase)->where('status', RequestStatus::ClosedWon->value)->count();
        $lost = (clone $reqBase)->where('status', RequestStatus::ClosedLost->value)->count();

        $byScope = fn ($r) => $r->where($scope);
        $quotations = Quotation::whereHas('request', $byScope)->count();
        $invBase = Invoice::whereHas('request', $byScope);
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
     * Последние заявки организации (точная привязка + fallback по email).
     *
     * @return \Illuminate\Support\Collection<int, RequestModel>
     */
    #[Computed]
    public function recentRequests()
    {
        return RequestModel::query()
            ->where($this->requestScope($this->organization->contactEmails()))
            ->with('assignedUser:id,name')
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'internal_code', 'status', 'subject', 'client_email', 'assigned_user_id', 'created_at']);
    }

    public function render()
    {
        return view('livewire.clients.show');
    }
}
