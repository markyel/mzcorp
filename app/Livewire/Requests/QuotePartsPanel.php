<?php

namespace App\Livewire\Requests;

use App\Models\QuotePartsRfq;
use App\Models\Request as RequestModel;
use App\Models\RequestItem;
use App\Models\Supplier;
use App\Services\QuoteParts\QuotePartsPilot;
use App\Services\QuoteParts\QuotePartsRfqService;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Вкладка «Поставщики» заявки: канал «Запрос через QuoteParts» для участников пилота
 * (при включённом рубильнике) и ответы по уже отправленным через QuoteParts запросам.
 * Старая форма запроса поставщикам рядом не меняется.
 */
class QuotePartsPanel extends Component
{
    public int $requestId;

    public bool $open = false;

    /** @var array<int|string, bool> request_item_id => отмечено */
    public array $items = [];

    /** @var array<int|string, string> request_item_id => количество */
    public array $qty = [];

    /** @var array<int|string, bool> supplier_id => отмечено */
    public array $suppliers = [];

    public string $replyBy = '';

    public string $message = '';

    public ?string $error = null;

    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
        $this->replyBy = now()->addDays(7)->toDateString();

        foreach ($this->catalogItems as $it) {
            $this->qty[$it->id] = $it->parsed_qty !== null ? rtrim(rtrim(number_format((float) $it->parsed_qty, 3, '.', ''), '0'), '.') : '1';
        }
    }

    #[Computed]
    public function enabled(): bool
    {
        return QuotePartsPilot::enabledFor(auth()->user());
    }

    #[Computed]
    public function catalogItems()
    {
        return RequestItem::query()
            ->where('request_id', $this->requestId)->where('is_active', true)
            ->with('catalogItem:id,sku,name,brand,brand_article')
            ->orderBy('position')->get();
    }

    #[Computed]
    public function activeSuppliers()
    {
        return Supplier::query()->where('quoteparts_status', 'active')->orderBy('name')->get(['id', 'name', 'email']);
    }

    #[Computed]
    public function rfqs()
    {
        return QuotePartsRfq::query()->where('request_id', $this->requestId)->with('createdBy:id,name')->latest('id')->get();
    }

    public function send(QuotePartsRfqService $service): void
    {
        abort_unless($this->enabled, 403);
        $this->error = null;

        $chosen = array_map('intval', array_keys(array_filter($this->items)));
        $quantities = [];
        foreach ($this->catalogItems->whereIn('id', $chosen) as $it) {
            if ($it->catalog_item_id === null) {
                continue;
            }
            $q = (float) str_replace(',', '.', (string) ($this->qty[$it->id] ?? '1'));
            if ($q <= 0) {
                $this->error = 'Количество должно быть больше нуля: '.($it->catalogItem?->sku ?? $it->parsed_name);

                return;
            }
            $quantities[(int) $it->catalog_item_id] = ($quantities[(int) $it->catalog_item_id] ?? 0) + $q;
        }
        $supplierIds = array_map('intval', array_keys(array_filter($this->suppliers)));

        if ($quantities === [] || $supplierIds === []) {
            $this->error = 'Отметьте позиции с M-артикулом и хотя бы одного поставщика.';

            return;
        }

        try {
            $rfq = $service->send(RequestModel::findOrFail($this->requestId), $quantities, $supplierIds, $this->replyBy ?: null, $this->message, auth()->user());
        } catch (Throwable $e) {
            $this->error = mb_substr($e->getMessage(), 0, 400);

            return;
        }

        $this->reset('items', 'suppliers', 'message', 'open');
        unset($this->rfqs);
        $this->dispatch('toast', type: 'success', message: 'Запрос '.$rfq->tag.' отправлен через QuoteParts.');
    }

    public function refresh(int $id, QuotePartsRfqService $service): void
    {
        $rfq = QuotePartsRfq::query()->where('request_id', $this->requestId)->findOrFail($id);
        try {
            $service->refresh($rfq);
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: 'QuoteParts не ответил: '.mb_substr($e->getMessage(), 0, 150));
        }
        unset($this->rfqs);
    }

    public function render()
    {
        $supplierNames = Supplier::query()
            ->whereIn('id', $this->rfqs->flatMap(fn ($r) => collect($r->snapshot['suppliers'] ?? [])->pluck('external_id'))->filter()->unique())
            ->pluck('name', 'id');

        return view('livewire.requests.quote-parts-panel', ['supplierNames' => $supplierNames]);
    }
}
