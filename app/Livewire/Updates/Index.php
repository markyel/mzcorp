<?php

namespace App\Livewire\Updates;

use App\Models\ChangelogEntry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Публичная лента раздела «Обновления». Доступна всем авторизованным ролям
 * без разделения. При открытии сбрасывает счётчик непрочитанного
 * (users.updates_seen_at = now()).
 *
 * Привилегированные роли (head_of_sales/director/admin) дополнительно видят
 * кнопку «Управление» → updates.manage.
 */
class Index extends Component
{
    use WithPagination;

    public function mount(): void
    {
        // Сброс бейджа непрочитанного. Делаем тихо через forceFill, чтобы не
        // трогать updated_at и прочие наблюдатели.
        $user = Auth::user();
        if ($user) {
            $user->forceFill(['updates_seen_at' => now()])->saveQuietly();
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return (bool) Auth::user()?->hasAnyRole(['head_of_sales', 'director', 'admin']);
    }

    #[Computed]
    public function entries()
    {
        return ChangelogEntry::query()
            ->published()
            ->latest('published_at')
            ->paginate(20);
    }

    public function render()
    {
        return view('livewire.updates.index');
    }
}
