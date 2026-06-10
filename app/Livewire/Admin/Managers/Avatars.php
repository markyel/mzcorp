<?php

namespace App\Livewire\Admin\Managers;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Загрузка аватарок пользователя (3 варианта: нейтральный / победитель /
 * проигравший). Доступ — privileged (head_of_sales/director/admin).
 * Файлы кладутся на диск `local` в avatars/{user_id}/, путь пишется в
 * users.avatar_*_path. Победитель/проигравший показываются в карточке
 * заявки по статусу закрытия, нейтральный — в списке и карточке.
 */
class Avatars extends Component
{
    use WithFileUploads;

    public User $user;

    #[Validate('nullable|image|mimes:png,jpg,jpeg,webp|max:1024')]
    public $fileNeutral = null;

    #[Validate('nullable|image|mimes:png,jpg,jpeg,webp|max:1024')]
    public $fileWon = null;

    #[Validate('nullable|image|mimes:png,jpg,jpeg,webp|max:1024')]
    public $fileLost = null;

    /** variant => [prop, column, label]. */
    private const MAP = [
        'neutral' => ['fileNeutral', 'avatar_neutral_path', 'Нейтральная'],
        'won' => ['fileWon', 'avatar_won_path', 'Победитель'],
        'lost' => ['fileLost', 'avatar_lost_path', 'Проигравший'],
    ];

    public function mount(User $user): void
    {
        $this->ensureCanManage();
        $this->user = $user;
    }

    private function ensureCanManage(): void
    {
        abort_unless(
            (bool) auth()->user()?->hasAnyRole(['head_of_sales', 'director', 'admin']),
            403,
        );
    }

    public function updatedFileNeutral(): void
    {
        $this->store('neutral');
    }

    public function updatedFileWon(): void
    {
        $this->store('won');
    }

    public function updatedFileLost(): void
    {
        $this->store('lost');
    }

    private function store(string $variant): void
    {
        $this->ensureCanManage();

        [$prop, $col] = self::MAP[$variant];
        $file = $this->{$prop};
        if (! $file) {
            return;
        }

        $this->validateOnly($prop);

        // Удаляем прежний файл, чтобы не копить мусор.
        $old = $this->user->{$col};
        if ($old) {
            Storage::disk(User::AVATAR_DISK)->delete($old);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        $name = $variant . '-' . Str::random(8) . '.' . $ext;
        $path = $file->storeAs('avatars/' . $this->user->id, $name, User::AVATAR_DISK);

        $this->user->forceFill([$col => $path])->save();
        $this->{$prop} = null;

        session()->flash('avatarStatus', 'Аватарка обновлена.');
    }

    public function remove(string $variant): void
    {
        $this->ensureCanManage();

        if (! isset(self::MAP[$variant])) {
            return;
        }
        [, $col] = self::MAP[$variant];

        $old = $this->user->{$col};
        if ($old) {
            Storage::disk(User::AVATAR_DISK)->delete($old);
        }
        $this->user->forceFill([$col => null])->save();

        session()->flash('avatarStatus', 'Аватарка удалена.');
    }

    public function render()
    {
        return view('livewire.admin.managers.avatars', [
            'variants' => self::MAP,
        ]);
    }
}
