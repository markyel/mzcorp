<?php

namespace App\Livewire\QuoteParts;

use App\Jobs\QuoteParts\SyncQuotePartsPoolJob;
use App\Models\QuotePartsPoolItem;
use App\Services\Procurement\PriceRefreshCandidates;
use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\QuotePartsPoolService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Раздел «QuoteParts» (на время тестирования платформы): тот же пул, что «К актуализации»
 * в «Снабжении», с выбором группы позиций для передачи в QuoteParts, и сами переданные
 * позиции со статусом из QuoteParts. Доступ — директорат и админ.
 */
class Index extends Component
{
    public const PER_PAGE = 50;

    #[Url(except: 'candidates')]
    public string $tab = 'candidates';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public int $perPage = self::PER_PAGE;

    /** @var array<int|string, bool> catalog_item_id => отмечено (вкладка кандидатов) */
    public array $selected = [];

    /** @var array<int|string, bool> pool item id => отмечено (вкладка «В QuoteParts») */
    public array $selectedPool = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasAnyRole(['director', 'admin']), 403);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['candidates', 'pool', 'suppliers'], true) ? $tab : 'candidates';
        $this->perPage = self::PER_PAGE;
    }

    public function updatedSearch(): void
    {
        $this->perPage = self::PER_PAGE;
    }

    public function loadMore(): void
    {
        $this->perPage += self::PER_PAGE;
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function candidates(): array
    {
        $rows = app(PriceRefreshCandidates::class)->list();
        $search = trim($this->search);

        if ($search !== '') {
            $rows = array_values(array_filter($rows,
                fn ($r) => mb_stripos($r['sku'].' '.$r['name'].' '.(string) $r['brand'], $search) !== false));
        }

        return $rows;
    }

    /** @return array<int, QuotePartsPoolItem> catalog_item_id => позиция пула */
    #[Computed]
    public function poolByCatalogId(): array
    {
        return QuotePartsPoolItem::query()->inPool()->get()->keyBy('catalog_item_id')->all();
    }

    #[Computed]
    public function poolItems()
    {
        $search = trim($this->search);

        return QuotePartsPoolItem::query()->inPool()
            ->with('catalogItem:id,sku,name,brand')
            ->when($search !== '', fn ($q) => $q->whereHas('catalogItem', fn ($c) => $c
                ->where('sku', 'ilike', '%'.$search.'%')->orWhere('name', 'ilike', '%'.$search.'%')))
            ->orderByRaw("case status when 'paused' then 0 when 'error' then 1 when 'pending' then 2 else 3 end")
            ->orderBy('sku')
            ->get();
    }

    /** @return array<string, int> */
    #[Computed]
    public function poolCounts(): array
    {
        return QuotePartsPoolItem::query()->inPool()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
    }

    /** Поставщики, приглашённые в QuoteParts (приглашают из карточки поставщика). */
    #[Computed]
    public function invitedSuppliers()
    {
        return \App\Models\Supplier::query()->whereNotNull('quoteparts_link_id')
            ->orderByRaw("case quoteparts_status when 'terms_discussed' then 0 when 'invited' then 1 when 'active' then 2 else 3 end")
            ->orderByDesc('quoteparts_invited_at')
            ->get(['id', 'name', 'email', 'quoteparts_status', 'quoteparts_invited_at', 'quoteparts_comment']);
    }

    public function refreshSupplierStatuses(\App\Services\QuoteParts\QuotePartsSupplierService $service): void
    {
        try {
            $n = $service->refreshStatuses();
        } catch (Throwable) {
            $n = 0;
        }
        unset($this->invitedSuppliers);
        $this->dispatch('toast', type: 'success', message: 'Статусы поставщиков обновлены'.($n ? ': '.$n : '').'.');
    }

    #[Computed]
    public function configured(): bool
    {
        return app(QuotePartsClient::class)->isConfigured();
    }

    public function selectShown(): void
    {
        foreach (array_slice($this->candidates, 0, $this->perPage) as $row) {
            if (! isset($this->poolByCatalogId[$row['cid']])) {
                $this->selected[$row['cid']] = true;
            }
        }
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectedPool = [];
    }

    public function transfer(QuotePartsPoolService $pool): void
    {
        $ids = array_map('intval', array_keys(array_filter($this->selected)));
        if ($ids === []) {
            return;
        }

        $result = $pool->add($ids, auth()->user());
        SyncQuotePartsPoolJob::dispatch();

        $this->selected = [];
        unset($this->poolByCatalogId, $this->poolItems, $this->poolCounts);
        $this->dispatch('toast', type: 'success', message: 'В QuoteParts передаётся позиций: '.$result['added']
            .($result['already'] ? ', уже были в пуле: '.$result['already'] : '').'. Статус обновится через минуту.');
    }

    public function remove(QuotePartsPoolService $pool): void
    {
        $ids = array_map('intval', array_keys(array_filter($this->selectedPool)));
        if ($ids === []) {
            return;
        }

        try {
            $n = $pool->remove($ids);
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: 'QuoteParts не ответил: '.mb_substr($e->getMessage(), 0, 200));

            return;
        }

        $this->selectedPool = [];
        unset($this->poolByCatalogId, $this->poolItems, $this->poolCounts);
        $this->dispatch('toast', type: 'success', message: 'Убрано из QuoteParts: '.$n.'. История там сохраняется.');
    }

    public function refreshStatuses(QuotePartsPoolService $pool): void
    {
        SyncQuotePartsPoolJob::dispatch();
        $n = 0;
        try {
            $n = $pool->refreshPausedStatuses();
        } catch (Throwable) {
        }
        unset($this->poolByCatalogId, $this->poolItems, $this->poolCounts);
        $this->dispatch('toast', type: 'success', message: $n > 0 ? 'Статусы обновлены: '.$n.'.' : 'Статусы актуальны; недошедшие позиции отправятся повторно.');
    }

    public function render()
    {
        return view('livewire.quoteparts.index');
    }
}
