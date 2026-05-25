<?php

namespace App\Livewire\Support;

use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Админский инбокс — все тикеты системы. Фильтры по статусу и автору.
 *
 * Доступ к страничному маршруту ограничен role:admin в web.php;
 * здесь дополнительная защита на render.
 */
class Inbox extends Component
{
    use WithPagination;

    #[Url(as: 'status')]
    public string $statusFilter = 'open';

    #[Url(as: 'q')]
    public string $search = '';

    public function setStatus(string $status): void
    {
        $this->statusFilter = $status;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $query = SupportTicket::query()
            ->with(['user', 'assignee'])
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'resolved' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at');

        if ($this->statusFilter === 'open_any') {
            $query->open();
        } elseif (in_array($this->statusFilter, SupportTicketStatus::values(), true)) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search !== '') {
            $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $this->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('subject', 'ilike', $term)
                  ->orWhere('body', 'ilike', $term)
                  ->orWhereHas('user', fn ($u) => $u->where('name', 'ilike', $term)
                      ->orWhere('email', 'ilike', $term));
            });
        }

        return view('livewire.support.inbox', [
            'tickets' => $query->paginate(25),
            'statuses' => SupportTicketStatus::cases(),
        ]);
    }
}
