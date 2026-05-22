<?php

namespace App\Livewire\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoices\InvoiceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Раздел «Счета» (/dashboard/invoices) — Phase 4 step 3.
 *
 * Кто видит:
 *  - manager — только свои Invoice (через request.assigned_user_id).
 *  - head_of_sales / director / secretary / admin — все.
 *
 * Actions:
 *  - markPaid(id)  — invoice.status=paid + Request → Paid
 *  - cancel(id, reason) — invoice.status=cancelled + Request → AwaitingInvoice
 *
 * Фильтры: статус, период, поиск по номеру, менеджер (для privileged).
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'status')]
    public string $statusFilter = 'pending';

    #[Url(as: 'period')]
    public string $period = '30d';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'mgr')]
    public ?int $managerId = null;

    #[Url(as: 'scope')]
    public string $scope = 'mine';   // mine | all (для privileged)

    /** Confirm-state для cancel: id invoice, reason. */
    public ?int $cancellingInvoiceId = null;
    public string $cancellationReason = '';

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        // По умолчанию privileged видят all, менеджеры — mine.
        if ($this->canSeeAll()) {
            // если не задан явно в URL — все
            if (! request()->has('scope')) {
                $this->scope = 'all';
            }
        } else {
            $this->scope = 'mine'; // менеджеру не разрешаем «all» через URL
        }
    }

    /* ------------------------------- Filters -------------------------------- */

    public function setStatus(string $s): void
    {
        $allowed = ['all', 'pending', 'paid', 'expired', 'cancelled', 'overdue'];
        $this->statusFilter = in_array($s, $allowed, true) ? $s : 'pending';
        $this->resetPage();
    }

    public function setPeriod(string $p): void
    {
        $this->period = in_array($p, ['today', '7d', '30d', '90d', 'all'], true) ? $p : '30d';
        $this->resetPage();
    }

    public function setScope(string $s): void
    {
        if (! $this->canSeeAll()) {
            return; // менеджеры не могут переключать
        }
        $this->scope = $s === 'all' ? 'all' : 'mine';
        $this->resetPage();
    }

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingManagerId(): void { $this->resetPage(); }

    /* ----------------------------- Permissions ------------------------------ */

    public function canSeeAll(): bool
    {
        $u = auth()->user();
        return $u?->hasAnyRole([
            Role::HeadOfSales->value,
            Role::Director->value,
            Role::Secretary->value,
            Role::Admin->value,
        ]) ?? false;
    }

    private function canAct(Invoice $invoice): bool
    {
        $u = auth()->user();
        if (! $u) {
            return false;
        }
        if ($this->canSeeAll()) {
            return true;
        }
        // менеджер — только если invoice его заявки
        return $invoice->request?->assigned_user_id === $u->id;
    }

    /* -------------------------------- Actions ------------------------------- */

    public function markPaid(int $invoiceId, InvoiceService $service): void
    {
        $invoice = Invoice::with('request')->findOrFail($invoiceId);
        if (! $this->canAct($invoice)) {
            $this->dispatch('toast', message: 'Нет прав на оплату счёта.', type: 'error');
            return;
        }
        try {
            $service->markPaid($invoice, auth()->user());
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Ошибка: ' . $e->getMessage(), type: 'error');
            return;
        }
        $this->dispatch('toast', message: "Счёт №{$invoice->invoice_number} помечен оплаченным.", type: 'success');
    }

    public function startCancel(int $invoiceId): void
    {
        $this->cancellingInvoiceId = $invoiceId;
        $this->cancellationReason = '';
    }

    public function cancelStartCancel(): void
    {
        $this->cancellingInvoiceId = null;
        $this->cancellationReason = '';
    }

    public function confirmCancel(InvoiceService $service): void
    {
        if (! $this->cancellingInvoiceId) {
            return;
        }
        $invoice = Invoice::with('request')->findOrFail($this->cancellingInvoiceId);
        if (! $this->canAct($invoice)) {
            $this->dispatch('toast', message: 'Нет прав на аннулирование счёта.', type: 'error');
            $this->cancellingInvoiceId = null;
            return;
        }
        $reason = trim($this->cancellationReason);
        if ($reason === '') {
            $this->dispatch('toast', message: 'Укажите причину аннулирования.', type: 'error');
            return;
        }
        try {
            $service->cancel($invoice, $reason, auth()->user());
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Ошибка: ' . $e->getMessage(), type: 'error');
            return;
        }
        $this->dispatch('toast', message: "Счёт №{$invoice->invoice_number} аннулирован.", type: 'success');
        $this->cancellingInvoiceId = null;
        $this->cancellationReason = '';
    }

    /* ------------------------------ Computed -------------------------------- */

    #[Computed]
    public function invoices()
    {
        return $this->buildQuery()
            ->with([
                'request:id,internal_code,assigned_user_id,status,client_name,client_email',
                'request.assignedUser:id,name',
                'createdByUser:id,name',
                'paidByUser:id,name',
            ])
            ->orderByRaw('
                CASE status
                    WHEN \'pending\' THEN 1
                    WHEN \'expired\' THEN 2
                    WHEN \'paid\' THEN 3
                    WHEN \'cancelled\' THEN 4
                    ELSE 5
                END
            ')
            ->orderByDesc('id')
            ->paginate(50);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function availableManagers(): Collection
    {
        return User::query()
            ->role(Role::requestHandlerRoles())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function buildQuery(): Builder
    {
        $q = Invoice::query();

        // Scope: mine vs all (для privileged).
        $u = auth()->user();
        if ($this->scope === 'mine' || ! $this->canSeeAll()) {
            $q->whereHas('request', fn ($r) => $r->where('assigned_user_id', $u->id));
        } elseif ($this->managerId) {
            $q->whereHas('request', fn ($r) => $r->where('assigned_user_id', $this->managerId));
        }

        // Статус.
        if ($this->statusFilter === 'overdue') {
            // overdue = pending + expires_at < now
            $q->where('status', InvoiceStatus::Pending->value)
              ->where('expires_at', '<', now());
        } elseif ($this->statusFilter !== 'all'
            && in_array($this->statusFilter, InvoiceStatus::values(), true)) {
            $q->where('status', $this->statusFilter);
        }

        // Период (по issued_at).
        $cutoff = match ($this->period) {
            'today' => now()->startOfDay(),
            '7d'    => now()->subDays(7),
            '30d'   => now()->subDays(30),
            '90d'   => now()->subDays(90),
            default => null,
        };
        if ($cutoff !== null) {
            $q->where('issued_at', '>=', $cutoff);
        }

        // Search по invoice_number.
        if ($this->search !== '') {
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $this->search) . '%';
            $q->where('invoice_number', 'ilike', $needle);
        }

        return $q;
    }

    public function statusChipClass(string $status): string
    {
        $enum = InvoiceStatus::tryFrom($status);
        return $enum?->chipClass() ?? 'chip-neutral';
    }

    public function statusLabel(string $status): string
    {
        return InvoiceStatus::tryFrom($status)?->label() ?? $status;
    }

    public function render()
    {
        return view('livewire.invoices.index');
    }
}
