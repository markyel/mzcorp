<?php

namespace App\Livewire\Clients;

use App\Models\Organization;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Раздел «Клиенты» — список организаций. Доступен всем ролям (редактирование
 * тоже). Поиск по названию / ИНН / email|ФИО контакта. Создание — инлайн.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $creating = false;
    public string $newName = '';
    public string $newInn = '';

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
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

    #[Computed]
    public function organizations()
    {
        $q = Organization::query()->withCount('contacts');

        $s = trim($this->search);
        if ($s !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('name', 'ilike', $like)
                    ->orWhere('inn', 'ilike', $like)
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
