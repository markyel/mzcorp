<?php

namespace App\Livewire\Requests;

use App\Livewire\Concerns\AnswersQuotePartsSuppliers;
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
    use AnswersQuotePartsSuppliers;

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

    public string $supplierSearch = '';

    /** @var list<int> поставщики из справочника, добавленные поиском */
    public array $extraSupplierIds = [];

    /**
     * Поставщики, уже известные QuoteParts (с любым статусом: работаем, приглашён, переписка,
     * отказался от платформы), — одна строка на организацию; плюс добавленные поиском.
     */
    #[Computed]
    public function activeSuppliers()
    {
        return Supplier::query()
            ->where(fn ($q) => $q->whereNotNull('quoteparts_link_id')->orWhereIn('id', $this->extraSupplierIds))
            ->with('organization:id,name')->orderBy('name')
            ->get(['id', 'name', 'email', 'supplier_organization_id', 'quoteparts_status'])
            ->sortBy(fn ($s) => [$s->quoteparts_status === 'active' ? 0 : 1, (string) $s->name])
            ->unique(fn ($s) => $s->quotepartsKey())
            ->values();
    }

    /** Поиск по всему справочнику: кого ещё нет в QuoteParts, заведём при отправке (режим переписки). */
    #[Computed]
    public function foundSuppliers()
    {
        $term = trim($this->supplierSearch);
        if (mb_strlen($term) < 2) {
            return collect();
        }
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        return Supplier::query()->whereNotNull('email')
            ->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('domain', 'ilike', $like))
            ->whereNull('quoteparts_link_id')->whereNotIn('id', $this->extraSupplierIds)
            ->orderBy('name')->limit(12)->get(['id', 'name', 'email']);
    }

    public function addSupplier(int $id): void
    {
        $this->extraSupplierIds[] = $id;
        $this->suppliers[$id] = true;
        $this->supplierSearch = '';
        unset($this->activeSuppliers, $this->foundSuppliers);
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

    protected function quotePartsRfqForAnswer(int $id): QuotePartsRfq
    {
        return QuotePartsRfq::query()->where('request_id', $this->requestId)->findOrFail($id);
    }

    protected function afterQuotePartsAnswer(): void
    {
        unset($this->rfqs);
    }

    public function render()
    {
        return view('livewire.requests.quote-parts-panel', ['supplierNames' => app(QuotePartsRfqService::class)->supplierNames($this->rfqs)]);
    }
}
