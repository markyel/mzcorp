<?php

namespace App\Livewire\Requests;

use App\Models\Request as RequestModel;
use App\Services\Invoices\InvoiceService;
use Carbon\Carbon;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Phase 4 — Выставить счёт по заявке.
 *
 * Открывается из action-panel Detail.blade.php по event `open-issue-invoice-dialog`.
 * Создаёт Invoice через InvoiceService::issue (вычисляет expires_at по
 * российскому производственному календарю + переводит Request в Invoiced).
 *
 * Permission: assigned manager / acting / privileged (через Detail $canManage).
 */
class IssueInvoiceDialog extends Component
{
    public int $requestId;
    public bool $open = false;

    #[Validate('required|string|min:1|max:64')]
    public string $invoiceNumber = '';

    #[Validate('required|date|before_or_equal:tomorrow')]
    public string $issuedAt = '';

    #[Validate('required|integer|min:1|max:60')]
    public int $validityDays = 5;

    #[Validate('nullable|string|max:1000')]
    public string $comment = '';

    public function mount(RequestModel $request): void
    {
        $this->requestId = $request->id;
    }

    #[On('open-issue-invoice-dialog')]
    public function show(): void
    {
        $this->invoiceNumber = '';
        $this->issuedAt = now()->format('Y-m-d');
        $this->validityDays = (int) config('services.invoices.default_validity_business_days', 5);
        $this->comment = '';
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function save(InvoiceService $service)
    {
        $this->validate();

        $req = RequestModel::findOrFail($this->requestId);

        // Permission — owner/acting/privileged. Совпадает с
        // RequestStateService::ensureCanTransition.
        $user = auth()->user();
        if (! $this->canIssue($req, $user)) {
            $this->addError('invoiceNumber', 'У вас нет прав на выставление счёта.');
            return null;
        }

        // Дубликат номера в рамках одной заявки — отбиваем (могут быть
        // одинаковые номера у разных заявок, но не у одной).
        $duplicate = $req->invoices()
            ->where('invoice_number', trim($this->invoiceNumber))
            ->exists();
        if ($duplicate) {
            $this->addError('invoiceNumber', 'Счёт с таким номером уже есть в этой заявке.');
            return null;
        }

        try {
            $invoice = $service->issue(
                request: $req,
                invoiceNumber: trim($this->invoiceNumber),
                issuedAt: Carbon::parse($this->issuedAt)->startOfDay(),
                validityDays: $this->validityDays,
                comment: $this->comment !== '' ? $this->comment : null,
                author: $user,
            );
        } catch (\Throwable $e) {
            $this->addError('invoiceNumber', 'Не удалось выставить счёт: ' . $e->getMessage());
            return null;
        }

        $this->open = false;
        $this->dispatch('request-state-changed');
        session()->flash('status', sprintf(
            'Счёт №%s выставлен. Действителен до %s.',
            $invoice->invoice_number,
            $invoice->expires_at->format('d.m.Y'),
        ));

        return $this->redirect(
            \Illuminate\Support\Facades\Request::header('Referer')
                ?: route('requests.show', $req),
            navigate: false,
        );
    }

    private function canIssue(RequestModel $req, ?\App\Models\User $user): bool
    {
        if (! $user) {
            return false;
        }
        $privileged = $user->hasAnyRole([
            \App\Enums\Role::HeadOfSales->value,
            \App\Enums\Role::Director->value,
            \App\Enums\Role::Admin->value,
        ]);
        if ($privileged) {
            return true;
        }
        return method_exists($req, 'isAccessibleBy')
            ? $req->isAccessibleBy($user)
            : $req->assigned_user_id === $user->id;
    }

    public function render()
    {
        return view('livewire.requests.issue-invoice-dialog');
    }
}
