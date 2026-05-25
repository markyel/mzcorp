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

    public function render()
    {
        $query = SupportTicket::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('created_at');

        if ($this->statusFilter !== 'all'
            && in_array($this->statusFilter, SupportTicketStatus::values(), true)) {
            $query->where('status', $this->statusFilter);
        }

        return view('livewire.support.my-tickets', [
            'tickets' => $query->paginate(20),
            'statuses' => SupportTicketStatus::cases(),
        ]);
    }
}
