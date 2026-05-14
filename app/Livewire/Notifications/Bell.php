<?php

namespace App\Livewire\Notifications;

use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Bell-icon в topbar — Livewire component с unread count + dropdown
 * последних 8 нотификаций (Foundation Фаза 2 — in-app reminders).
 *
 * Polling каждые 30 секунд через `wire:poll.30s`. Read-state хранится
 * в стандартной таблице `notifications` (Laravel DatabaseChannel).
 */
class Bell extends Component
{
    public bool $open = false;

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function markRead(string $id): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }
        $user->notifications()->whereKey($id)->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }
        $user->unreadNotifications->markAsRead();
    }

    #[Computed]
    public function unreadCount(): int
    {
        return (int) auth()->user()?->unreadNotifications()->count();
    }

    /**
     * Последние 8 — unread'ы сверху, прочитанные снизу.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, DatabaseNotification>
     */
    #[Computed]
    public function recent()
    {
        $user = auth()->user();
        if (! $user) {
            return collect();
        }

        return $user->notifications()
            ->orderByRaw('read_at IS NULL DESC')  // null = unread сначала
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();
    }

    public function render()
    {
        return view('livewire.notifications.bell');
    }
}
