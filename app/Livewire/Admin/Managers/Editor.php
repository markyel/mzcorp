<?php

namespace App\Livewire\Admin\Managers;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Создание / редактирование пользователя CRM (Phase 1.13).
 *
 * Поля: ФИО, email (логин), пароль (опционально для edit, обязателен для create),
 * роль (одна из 4-х). Сохраняем через User::create() / update() + spatie syncRoles().
 *
 * После save():
 *   create → редирект на managers.edit/{id} (там появится блок «Личный ящик»).
 *   update → flash + редирект на managers.index.
 */
class Editor extends Component
{
    public ?int $userId = null;

    #[Validate('required|string|min:2|max:120')]
    public string $name = '';

    #[Validate('required|email|max:200')]
    public string $email = '';

    public string $password = '';
    public string $passwordConfirmation = '';

    #[Validate('required|in:manager,head_of_sales,secretary,director,admin')]
    public string $role = 'manager';

    /**
     * Плановая нагрузка в % (100 — норма, 50 — в 2 раза меньше, 200 — в 2 раза больше).
     * Применяется только для ролей-исполнителей (manager / head_of_sales);
     * для secretary / director / admin поле скрыто, значение хранится но игнорируется
     * в AssignmentService (они не попадают в available()).
     */
    #[Validate('required|integer|between:10,500')]
    public int $loadWeight = 100;

    public function mount(?User $user = null): void
    {
        if ($user && $user->exists) {
            $this->userId = $user->id;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->role = $user->roles->first()?->name ?? 'manager';
            $this->loadWeight = (int) ($user->load_weight ?? 100);

            // Защита: не-админ не может открывать страницу редактирования
            // admin-юзера. РОП/директор не должны даже видеть, что такой есть.
            if ($user->hasRole(RoleEnum::Admin->value) && ! $this->currentIsAdmin()) {
                abort(403, 'Управление админ-учётками доступно только админу.');
            }
        }
    }

    /**
     * Текущий авторизованный пользователь — admin?
     */
    private function currentIsAdmin(): bool
    {
        return (bool) auth()->user()?->hasRole(RoleEnum::Admin->value);
    }

    /**
     * Список ролей, доступных для назначения текущим пользователем.
     * Не-админ не может назначить роль `admin`.
     *
     * @return array<int, RoleEnum>
     */
    private function availableRoles(): array
    {
        $all = RoleEnum::cases();
        if ($this->currentIsAdmin()) {
            return $all;
        }

        return array_values(array_filter($all, static fn (RoleEnum $r): bool => $r !== RoleEnum::Admin));
    }

    public function save()
    {
        $this->validate();

        // Запрет назначать admin не-админом (на случай обхода UI).
        if ($this->role === RoleEnum::Admin->value && ! $this->currentIsAdmin()) {
            $this->addError('role', 'Роль «Админ» может назначить только админ.');
            return null;
        }
        // Запрет править admin-учётку не-админом.
        if ($this->userId) {
            $target = User::find($this->userId);
            if ($target && $target->hasRole(RoleEnum::Admin->value) && ! $this->currentIsAdmin()) {
                abort(403);
            }
        }

        // Уникальность email — отдельной проверкой, чтобы корректно учитывать
        // edit (исключить текущую запись из уникальности).
        $duplicate = User::query()
            ->where('email', $this->email)
            ->when($this->userId, fn ($q) => $q->where('id', '!=', $this->userId))
            ->exists();
        if ($duplicate) {
            $this->addError('email', 'Пользователь с таким email уже существует.');

            return null;
        }

        // Пароль обязателен на create, опционален на edit.
        if (! $this->userId) {
            if (strlen($this->password) < 8) {
                $this->addError('password', 'Минимум 8 символов.');

                return null;
            }
            if ($this->password !== $this->passwordConfirmation) {
                $this->addError('passwordConfirmation', 'Пароли не совпадают.');

                return null;
            }
        } elseif ($this->password !== '' || $this->passwordConfirmation !== '') {
            if (strlen($this->password) < 8) {
                $this->addError('password', 'Минимум 8 символов.');

                return null;
            }
            if ($this->password !== $this->passwordConfirmation) {
                $this->addError('passwordConfirmation', 'Пароли не совпадают.');

                return null;
            }
        }

        if ($this->userId) {
            $user = User::findOrFail($this->userId);
            $user->fill([
                'name' => $this->name,
                'email' => $this->email,
                'load_weight' => $this->loadWeight,
            ]);
            if ($this->password !== '') {
                $user->password = $this->password; // hash через cast
            }
            $user->save();
            $user->syncRoles([$this->role]);

            session()->flash('status', "«{$user->name}» обновлён.");

            return $this->redirect(route('managers.index'), navigate: true);
        }

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password, // hash через cast
            'load_weight' => $this->loadWeight,
        ]);
        $user->assignRole($this->role);

        session()->flash('status', "Менеджер «{$user->name}» создан. Подключите личный ящик ниже.");

        // Редирект на edit, чтобы РОП сразу мог привязать ящик в том же сценарии.
        return $this->redirect(route('managers.edit', $user), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.managers.editor', [
            'roles' => $this->availableRoles(),
        ]);
    }
}
