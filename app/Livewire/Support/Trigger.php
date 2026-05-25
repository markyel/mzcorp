<?php

namespace App\Livewire\Support;

use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Иконка «связь с создателем» (▲) в шапке + badge с числом непрочитанных
 * ответов админа по тикетам пользователя.
 *
 * Клик по самой иконке открывает Livewire-модалку NewTicketModal (через
 * глобальный JS-делегат `data-support-trigger` в layouts/app.blade.php
 * — он же собирает context: url/route/viewport/UA).
 *
 * Badge крутится через wire:poll.30s (как у bell). Помечается прочитанным,
 * когда пользователь открывает страницу тикета (TicketView::mount).
 */
class Trigger extends Component
{
    #[Computed]
    public function unreadCount(): int
    {
        $user = auth()->user();
        if (! $user) {
            return 0;
        }
        return (int) $user->notifications()
            ->where('type', \App\Notifications\SupportTicketReplyNotification::class)
            ->whereNull('read_at')
            ->count();
    }

    public function render()
    {
        return view('livewire.support.trigger');
    }
}
