<?php

namespace App\Livewire\Support;

use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Страница «Мои обращения» — список тикетов текущего пользователя
 * с фильтром по статусу.
 *
 * Просьба пользователя (2026-07-09):
 *  - тикеты со СВЕЖИМ (непрочитанным) ответом создателя выделяются жирным
 *    + чип «новый ответ» (непрочитанность = database-notification
 *    SupportTicketReplyNotification без read_at, как у badge ▲);
 *  - флажок 🚩 «вернуться позже»: отмеченные всплывают наверх списка и
 *    остаются выделенными, пока флажок не снят.
 */
class MyTickets extends Component
{
    use WithPagination;

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    public function setStatus(string $status): void
    {
        $this->statusFilter = $status;
        $this->resetPage();
    }

    /** Флажок «вернуться позже» (только на своих тикетах). */
    public function toggleFlag(int $ticketId): void
    {
        $ticket = SupportTicket::query()
            ->whereKey($ticketId)
            ->where('user_id', auth()->id())
            ->first();
        if ($ticket === null) {
            return;
        }
        $ticket->forceFill(['flagged_at' => $ticket->flagged_at === null ? now() : null])->save();
    }

    /**
     * ID тикетов с непрочитанным ответом создателя.
     *
     * @return array<int, true>
     */
    private function unreadTicketIds(): array
    {
        $ids = [];
        $rows = auth()->user()?->notifications()
            ->where('type', \App\Notifications\SupportTicketReplyNotification::class)
            ->whereNull('read_at')
            ->get(['data']) ?? collect();
        foreach ($rows as $n) {
            $tid = (int) (($n->data['ticket_id'] ?? 0));
            if ($tid > 0) {
                $ids[$tid] = true;
            }
        }

        return $ids;
    }

    public function render()
    {
        $query = SupportTicket::query()
            ->where('user_id', auth()->id())
            // Флагнутые «вернуться позже» — наверху, дальше свежие.
            ->orderByRaw('flagged_at IS NULL')
            ->orderByDesc('created_at');

        if ($this->statusFilter !== 'all'
            && in_array($this->statusFilter, SupportTicketStatus::values(), true)) {
            $query->where('status', $this->statusFilter);
        }

        return view('livewire.support.my-tickets', [
            'tickets' => $query->paginate(20),
            'statuses' => SupportTicketStatus::cases(),
            'unreadIds' => $this->unreadTicketIds(),
        ]);
    }
}
