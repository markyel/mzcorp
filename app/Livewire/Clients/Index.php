<?php

namespace App\Livewire\Clients;

use App\Models\ClientContact;
use App\Models\Organization;
use App\Models\Request as RequestModel;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Раздел «Клиенты» — две вкладки: «Контакты» (все e-mail заказчиков, основная
 * единица) и «Организации» (реестр юр.лиц с реквизитами/скидкой). Доступ и
 * редактирование — все роли.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'tab', except: 'contacts')]
    public string $tab = 'contacts';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /* --- Инлайн-создание организации --- */
    public bool $creating = false;
    public string $newName = '';
    public string $newInn = '';

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    public function setTab(string $t): void
    {
        $this->tab = in_array($t, ['contacts', 'organizations'], true) ? $t : 'contacts';
        $this->creating = false;
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function startCreate(): void
    {
        $this->creating = true;
        $this->newName = '';
        $this->newInn = '';
        $this->resetErrorBag();
    }

    public function cancelCreate(): void
    {
        $this->creating = false;
    }

    public function createOrganization()
    {
        $this->validate([
            'newName' => 'required|string|max:255',
            'newInn' => 'nullable|string|max:20',
        ], [], ['newName' => 'название', 'newInn' => 'ИНН']);

        $org = Organization::create([
            'name' => trim($this->newName),
            'inn' => trim($this->newInn) !== '' ? trim($this->newInn) : null,
        ]);

        return $this->redirectRoute('clients.show', ['organization' => $org->id], navigate: true);
    }

    /* ------------------------------ Контакты -------------------------------- */

    #[Computed]
    public function contacts()
    {
        $q = ClientContact::query()->withCount('organizations');

        $s = trim($this->search);
        if ($s !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('email', 'ilike', $like)
                    ->orWhere('full_name', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like);
            });
        }

        return $q->orderBy('email')->paginate(40);
    }

    /**
     * Кол-во заявок по email'ам текущей страницы контактов (одним запросом).
     *
     * @return array<string, int>
     */
    #[Computed]
    public function contactRequestCounts(): array
    {
        $emails = collect($this->contacts->items())
            ->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->all();
        if ($emails === []) {
            return [];
        }

        return RequestModel::query()
            ->whereIn(DB::raw('lower(client_email)'), $emails)
            ->groupBy(DB::raw('lower(client_email)'))
            ->selectRaw('lower(client_email) AS e, COUNT(*) AS c')
            ->pluck('c', 'e')
            ->all();
    }

    /* ----------------------------- Организации ------------------------------ */

    #[Computed]
    public function organizations()
    {
        $q = Organization::query()->withCount(['contacts', 'requests']);

        $s = trim($this->search);
        if ($s !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('name', 'ilike', $like)
                    ->orWhere('inn', 'ilike', $like)
                    ->orWhere('kpp', 'ilike', $like)
                    ->orWhereHas('contacts', fn ($c) => $c
                        ->where('email', 'ilike', $like)
                        ->orWhere('full_name', 'ilike', $like));
            });
        }

        return $q->orderBy('name')->paginate(30);
    }

    public function render()
    {
        return view('livewire.clients.index');
    }
}
