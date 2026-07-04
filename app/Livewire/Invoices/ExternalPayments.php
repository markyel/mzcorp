<?php

namespace App\Livewire\Invoices;

use App\Enums\Role;
use App\Models\ImportedPayment;
use App\Models\Request;
use App\Services\Invoices\PaymentImportService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * «Внешние оплаты» — оплаченные по банку (импорт 1С) счета, которых нет в CRM.
 * Привилегированные + секретарь: привязать к заявке (создаётся счёт + оплата),
 * игнорировать (LiftWay/непрофильные), вернуть из игнора. Автопривязка при
 * появлении счёта с тем же номером — InvoiceService::applyExternalPaymentIfAny.
 */
class ExternalPayments extends Component
{
    use WithPagination;

    #[Url(as: 'tab', except: 'unknown')]
    public string $tab = 'unknown';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Привязка: id записи журнала + код заявки. */
    public ?int $linkingId = null;

    public string $linkCode = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasAnyRole([
            Role::HeadOfSales->value, Role::Director->value,
            Role::Secretary->value, Role::Admin->value,
        ]), 403);
    }

    public function setTab(string $t): void
    {
        $this->tab = in_array($t, ['unknown', 'ignored', 'linked'], true) ? $t : 'unknown';
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function payments()
    {
        $q = ImportedPayment::query()
            ->where('outcome', match ($this->tab) {
                'ignored' => ImportedPayment::OUTCOME_IGNORED,
                'linked' => ImportedPayment::OUTCOME_LINKED,
                default => ImportedPayment::OUTCOME_UNKNOWN,
            })
            ->with(['import.uploadedBy:id,name', 'request:id,internal_code', 'resolvedBy:id,name']);

        $s = trim($this->search);
        if ($s !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $s).'%';
            $q->where(fn ($w) => $w->where('invoice_number', 'ilike', $like)
                ->orWhere('client_name', 'ilike', $like)
                ->orWhere('manager_name', 'ilike', $like));
        }

        return $q->orderByDesc('paid_sum')->paginate(50);
    }

    /** @return array{unknown: int, ignored: int, linked: int} */
    #[Computed]
    public function counts(): array
    {
        $rows = ImportedPayment::query()
            ->selectRaw('outcome, count(*) c')
            ->whereIn('outcome', [ImportedPayment::OUTCOME_UNKNOWN, ImportedPayment::OUTCOME_IGNORED, ImportedPayment::OUTCOME_LINKED])
            ->groupBy('outcome')
            ->pluck('c', 'outcome');

        return [
            'unknown' => (int) ($rows[ImportedPayment::OUTCOME_UNKNOWN] ?? 0),
            'ignored' => (int) ($rows[ImportedPayment::OUTCOME_IGNORED] ?? 0),
            'linked' => (int) ($rows[ImportedPayment::OUTCOME_LINKED] ?? 0),
        ];
    }

    public function ignore(int $id): void
    {
        $p = ImportedPayment::findOrFail($id);
        if ($p->outcome !== ImportedPayment::OUTCOME_UNKNOWN) {
            return;
        }
        $p->forceFill([
            'outcome' => ImportedPayment::OUTCOME_IGNORED,
            'resolved_at' => now(),
            'resolved_by_user_id' => auth()->id(),
        ])->save();
        unset($this->payments, $this->counts);
        $this->dispatch('toast', message: "Оплата по счёту {$p->invoice_number} помечена как неактуальная.", type: 'success');
    }

    public function restore(int $id): void
    {
        $p = ImportedPayment::findOrFail($id);
        if ($p->outcome !== ImportedPayment::OUTCOME_IGNORED) {
            return;
        }
        $p->forceFill(['outcome' => ImportedPayment::OUTCOME_UNKNOWN, 'resolved_at' => null, 'resolved_by_user_id' => null])->save();
        unset($this->payments, $this->counts);
    }

    public function startLink(int $id): void
    {
        $this->linkingId = $id;
        $this->linkCode = '';
    }

    public function cancelLink(): void
    {
        $this->linkingId = null;
        $this->linkCode = '';
    }

    public function confirmLink(PaymentImportService $service): void
    {
        if ($this->linkingId === null) {
            return;
        }
        $ext = ImportedPayment::findOrFail($this->linkingId);
        $code = trim($this->linkCode);
        $request = Request::query()
            ->where('internal_code', $code)
            ->orWhere('internal_code', 'M-'.ltrim($code, 'Mm-'))
            ->first();
        if ($request === null) {
            $this->addError('linkCode', 'Заявка с таким кодом не найдена.');

            return;
        }

        try {
            $invoice = $service->linkExternalToRequest($ext, $request, auth()->user());
        } catch (\Throwable $e) {
            $this->addError('linkCode', 'Ошибка: '.$e->getMessage());

            return;
        }

        $this->linkingId = null;
        $this->linkCode = '';
        unset($this->payments, $this->counts);
        $this->dispatch('toast', message: "Счёт №{$invoice->invoice_number} создан в заявке {$request->internal_code} и отмечен оплаченным.", type: 'success');
    }

    public function render()
    {
        return view('livewire.invoices.external-payments');
    }
}
