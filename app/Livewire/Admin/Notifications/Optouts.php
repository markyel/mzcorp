<?php

namespace App\Livewire\Admin\Notifications;

use App\Enums\ClientNotificationType;
use App\Models\ClientNotificationOptout;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Стоп-лист авто-уведомлений по e-mail клиента.
 *
 * Доступ: head_of_sales/director/admin. Указываем e-mail и чекбоксами
 * выбираем, какие типы уведомлений ОСТАВИТЬ (отмечено = слать). Остальные
 * заглушаются. Хранится явный список заглушённых типов (suppressed_types).
 */
class Optouts extends Component
{
    public string $search = '';

    public bool $showAddForm = false;

    #[Validate('required|email|max:255')]
    public string $newEmail = '';

    /** @var array<int, string> Значения типов, которые ОСТАВИТЬ (checked). */
    public array $newKeep = [];

    #[Validate('nullable|string|max:500')]
    public string $newComment = '';

    public ?string $flashMessage = null;
    public ?string $flashError = null;

    public function mount(): void
    {
        $this->ensureCanManage();
    }

    /** @return array<int, ClientNotificationType> */
    #[Computed]
    public function types(): array
    {
        return ClientNotificationType::cases();
    }

    #[Computed]
    public function entries()
    {
        $q = ClientNotificationOptout::query()->with('createdBy');
        $needle = trim($this->search);
        if ($needle !== '') {
            $q->whereRaw('LOWER(email) LIKE ?', ['%'.mb_strtolower($needle).'%']);
        }

        return $q->orderBy('email')->limit(500)->get();
    }

    public function toggleAddForm(): void
    {
        $this->showAddForm = ! $this->showAddForm;
        $this->reset(['newEmail', 'newKeep', 'newComment']);
        $this->resetValidation();
    }

    public function add(): void
    {
        $this->ensureCanManage();
        $this->flashMessage = null;
        $this->flashError = null;
        $this->validate();

        $email = mb_strtolower(trim($this->newEmail));

        // suppressed = все типы, КРОМЕ отмеченных «оставить».
        $allValues = array_map(fn (ClientNotificationType $t) => $t->value, ClientNotificationType::cases());
        $suppressed = array_values(array_diff($allValues, $this->newKeep));

        $entry = ClientNotificationOptout::updateOrCreate(
            ['email' => $email],
            [
                'suppressed_types' => $suppressed,
                'comment' => $this->newComment !== '' ? $this->newComment : null,
                'created_by_user_id' => Auth::id(),
            ],
        );

        $this->flashMessage = $entry->wasRecentlyCreated
            ? "Добавлено: {$email} (заглушено типов: ".count($suppressed).')'
            : "Обновлено: {$email} (заглушено типов: ".count($suppressed).')';

        $this->reset(['newEmail', 'newKeep', 'newComment']);
        $this->showAddForm = false;
        unset($this->entries);
    }

    /**
     * Переключить один тип у записи: оставить ↔ заглушить.
     */
    public function toggleType(int $id, string $typeValue): void
    {
        $this->ensureCanManage();
        if (ClientNotificationType::tryFrom($typeValue) === null) {
            return;
        }

        $entry = ClientNotificationOptout::find($id);
        if (! $entry) {
            return;
        }

        $suppressed = (array) $entry->suppressed_types;
        if (in_array($typeValue, $suppressed, true)) {
            $suppressed = array_values(array_diff($suppressed, [$typeValue])); // оставить
        } else {
            $suppressed[] = $typeValue; // заглушить
        }
        $entry->forceFill(['suppressed_types' => $suppressed])->save();
        unset($this->entries);
    }

    public function delete(int $id): void
    {
        $this->ensureCanManage();
        $entry = ClientNotificationOptout::find($id);
        if ($entry) {
            $email = $entry->email;
            $entry->delete();
            $this->flashMessage = "Удалено из стоп-листа: {$email}";
            unset($this->entries);
        }
    }

    public function render()
    {
        return view('livewire.admin.notifications.optouts');
    }

    private function ensureCanManage(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->hasAnyRole(['head_of_sales', 'director', 'admin'])) {
            abort(403);
        }
    }
}
