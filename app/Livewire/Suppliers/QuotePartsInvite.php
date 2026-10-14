<?php

namespace App\Livewire\Suppliers;

use App\Models\Supplier;
use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\QuotePartsSupplierService;
use Livewire\Component;
use Throwable;

/**
 * Блок «QuoteParts» в карточке поставщика: пригласить в QuoteParts с режимом цены
 * и видеть ответ поставщика. На время тестирования — директорат и админ.
 */
class QuotePartsInvite extends Component
{
    public const INCOTERMS = ['EXW', 'FCA', 'FAS', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'];

    public const CURRENCIES = ['USD', 'EUR', 'CNY', 'RUB'];

    public Supplier $supplier;

    public bool $open = false;

    public string $company_name = '';

    public string $contact_name = '';

    public string $email = '';

    public string $locale = 'en';

    public string $country = '';

    public string $incoterm = '';

    public string $incoterm_place = '';

    public string $currency = 'USD';

    public string $payment_terms = '';

    /** '' — не указано, '1' — включена в цену, '0' — не включена. */
    public string $packaging = '';

    public ?int $offer_validity_days = 90;

    public string $message = '';

    public ?string $error = null;

    public function mount(Supplier $supplier): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['director', 'admin']), 403);

        $this->supplier = $supplier;
        $this->company_name = (string) ($supplier->organization?->name ?: $supplier->name ?: $supplier->domain);
        $this->contact_name = (string) $supplier->contact_person;
        $this->email = (string) $supplier->email;
        $this->locale = 'en';
    }

    public function invite(QuotePartsSupplierService $service): void
    {
        $data = $this->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'locale' => ['required', 'in:en,zh_CN'],
            'country' => ['nullable', 'string', 'size:2'],
            'incoterm' => ['nullable', 'in:'.implode(',', self::INCOTERMS)],
            'incoterm_place' => ['nullable', 'string', 'max:255', 'required_with:incoterm'],
            'currency' => ['nullable', 'in:'.implode(',', self::CURRENCIES)],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'packaging' => ['nullable', 'in:,0,1'],
            'offer_validity_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'company_name' => 'компания', 'email' => 'почта', 'incoterm_place' => 'место',
            'country' => 'страна', 'offer_validity_days' => 'срок действия',
        ]);

        $data['packaging_included'] = $this->packaging === '' ? null : $this->packaging === '1';
        $this->error = null;

        try {
            $service->invite($this->supplier, $data);
        } catch (Throwable $e) {
            $this->error = mb_substr($e->getMessage(), 0, 400);

            return;
        }

        $this->supplier->refresh();
        $this->open = false;
        $this->dispatch('toast', type: 'success', message: 'Приглашение отправлено: '.$this->email);
    }

    public function refreshStatus(QuotePartsSupplierService $service): void
    {
        try {
            $service->refreshStatuses();
        } catch (Throwable) {
        }
        $this->supplier->refresh();
    }

    public function render()
    {
        return view('livewire.suppliers.quote-parts-invite', [
            'configured' => app(QuotePartsClient::class)->isConfigured(),
            'labels' => QuotePartsSupplierService::STATUS_LABELS,
        ]);
    }
}
