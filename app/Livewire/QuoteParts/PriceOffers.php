<?php

namespace App\Livewire\QuoteParts;

use App\Models\CatalogItem;
use App\Models\QuotePartsOffer;
use App\Services\Iqot\IqotCurrencyConverter;
use App\Services\QuoteParts\PriceRefreshReadiness;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Вкладка «Актуализация цен» раздела QuoteParts: свежие предложения поставщиков из QuoteParts
 * (форма и письмо по запросу, кабинет, прайс) рядом с ценами каталога — закупочной и продажной.
 *
 * Вид: по заявкам, по позициям (позиция и все предложения на неё) или списком. Сверху —
 * позиции, по которым предложений достаточно, чтобы актуализировать цену в базе
 * (PriceRefreshReadiness). Позицию можно раскрыть — полная карточка каталога.
 */
class PriceOffers extends Component
{
    public const DAYS = [3, 7, 30, 90];

    public const VIEWS = ['request' => 'по заявкам', 'item' => 'по позициям', 'list' => 'списком'];

    #[Url(as: 'pd', except: 7)]
    public int $days = 7;

    #[Url(as: 'pv', except: 'request')]
    public string $view = 'request';

    /** Показывать и заменённые / истёкшие предложения. */
    #[Url(as: 'pa', except: false)]
    public bool $withStale = false;

    #[Url(as: 'pq', except: '')]
    public string $search = '';

    /** @var list<int> раскрытые карточки позиций каталога */
    public array $openItems = [];

    public function toggleItem(int $catalogItemId): void
    {
        $this->openItems = in_array($catalogItemId, $this->openItems, true)
            ? array_values(array_diff($this->openItems, [$catalogItemId]))
            : [...$this->openItems, $catalogItemId];
    }

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function rows(): Collection
    {
        $since = now()->subDays(in_array($this->days, self::DAYS, true) ? $this->days : 7);

        return $this->query()
            ->where(fn ($q) => $q->where('remote_updated_at', '>=', $since)->orWhere('created_at', '>=', $since))
            ->limit(500)
            ->get()
            ->map(fn (QuotePartsOffer $o) => self::row($o));
    }

    private function query()
    {
        $term = trim($this->search);

        return QuotePartsOffer::query()
            ->with(['catalogItem:id,sku,name,brand,price,purchase_price,is_price_actual', 'request:id,internal_code,client_name,subject,assigned_user_id', 'request.assignedUser:id,name'])
            ->when(! $this->withStale, fn ($q) => $q->where('superseded', false)->whereIn('status', ['active', 'expiring']))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('sku', 'ilike', '%'.$term.'%')
                ->orWhere('supplier_name', 'ilike', '%'.$term.'%')
                ->orWhere('part_number', 'ilike', '%'.$term.'%')
                ->orWhere('rfq_tag', 'ilike', '%'.$term.'%')
                ->orWhereHas('catalogItem', fn ($c) => $c->where('name', 'ilike', '%'.$term.'%'))
                ->orWhereHas('request', fn ($r) => $r->where('internal_code', 'ilike', '%'.$term.'%'))))
            ->orderByDesc('remote_updated_at');
    }

    /** Кого о позиции спросили и кто ответил — по запросам QuoteParts (артикул => сводка). */
    #[Computed]
    public function readiness(): array
    {
        return app(PriceRefreshReadiness::class)->stats();
    }

    /**
     * Позиции, по которым предложений достаточно для актуализации цены: со всеми их действующими
     * предложениями за окно запросов, не только за выбранный период.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function readyItems(): Collection
    {
        $checker = app(PriceRefreshReadiness::class);
        $ready = array_keys(array_filter($this->readiness, fn ($st) => $checker->isReady($st)));
        if ($ready === []) {
            return collect();
        }
        $rows = $this->query()->whereIn('sku', $ready)
            ->where('created_at', '>=', now()->subDays(PriceRefreshReadiness::WINDOW_DAYS))
            ->get()->map(fn (QuotePartsOffer $o) => self::row($o));

        return $this->itemsFrom($rows)->values();
    }

    /** @return Collection<int, array<string, mixed>> позиция и все предложения на неё */
    #[Computed]
    public function items(): Collection
    {
        $ready = $this->readyItems->pluck('sku')->all();

        return $this->itemsFrom($this->rows->reject(fn ($r) => in_array($r['sku'], $ready, true)))->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function itemsFrom(Collection $rows): Collection
    {
        $checker = app(PriceRefreshReadiness::class);

        return $rows->groupBy(fn ($r) => $r['sku'] ?: 'offer-'.$r['id'])
            ->map(function (Collection $offers, string $sku) use ($checker) {
                $first = $offers->first();
                $st = $this->readiness[$sku] ?? null;
                $sorted = $offers->sortBy(fn ($r) => $r['rub'] ?? PHP_FLOAT_MAX)->values();

                return [
                    'sku' => $first['sku'],
                    'cid' => $first['cid'],
                    'name' => $first['name'],
                    'purchase' => $first['purchase'],
                    'sale' => $first['sale'],
                    'actual' => $first['actual'],
                    'offers' => $sorted,
                    'best' => $sorted->first(fn ($r) => $r['rub'] !== null && ! $r['stale']),
                    'asked' => $st ? count($st['asked']) : null,
                    'offered' => $st ? count($st['offered']) : null,
                    'declined' => $st ? count($st['declined']) : null,
                    'requested_at' => $st['requested_at'] ?? null,
                    'wait_until' => $st ? $checker->waitUntil($st['requested_at']) : null,
                    'rfqs' => $st['rfqs'] ?? [],
                    'at' => $offers->max('at'),
                ];
            })
            ->sortByDesc(fn ($i) => $i['at']?->getTimestamp() ?? 0);
    }

    /**
     * Группы по заявкам: свежий ответ — выше, «Без заявки» — последней.
     *
     * @return Collection<int, array{key:string, request:?\App\Models\Request, rfqs:list<string>, rows:Collection, at:mixed}>
     */
    #[Computed]
    public function groups(): Collection
    {
        if ($this->view === 'list') {
            return collect([['key' => 'all', 'request' => null, 'rfqs' => [], 'rows' => $this->rows, 'at' => null]]);
        }

        return $this->rows->groupBy(fn ($r) => $r['request_id'] ? 'r'.$r['request_id'] : 'none')
            ->map(fn (Collection $rows, string $key) => [
                'key' => $key,
                'request' => $rows->first()['request'],
                'rfqs' => $rows->pluck('rfq_tag')->filter()->unique()->values()->all(),
                'rows' => $rows,
                'at' => $rows->max('at'),
            ])
            ->sortBy(fn ($g) => [$g['key'] === 'none' ? 1 : 0, -($g['at']?->getTimestamp() ?? 0)])
            ->values();
    }

    /** Раскрытые карточки: позиция каталога и последнее изменение её цены. */
    #[Computed]
    public function openCards(): array
    {
        if ($this->openItems === []) {
            return [];
        }
        $cards = CatalogItem::query()->whereIn('id', $this->openItems)->get()->keyBy('id');
        $pcs = \App\Models\CatalogPriceChange::query()->whereIn('catalog_item_id', $this->openItems)
            ->orderByDesc('changed_at')->orderByDesc('id')->get()->unique('catalog_item_id')->keyBy('catalog_item_id');

        return $cards->map(fn ($c) => ['cat' => $c, 'pc' => $pcs->get($c->id)])->all();
    }

    /** @return array<string, mixed> */
    private static function row(QuotePartsOffer $o): array
    {
        $ci = $o->catalogItem;
        $rub = IqotCurrencyConverter::toRub($o->price !== null ? (float) $o->price : null, $o->currency);
        $purchase = $ci?->purchase_price !== null ? (float) $ci->purchase_price : null;

        return [
            'id' => $o->id,
            'at' => $o->remote_updated_at ?? $o->created_at,
            'request_id' => $o->request_id,
            'request' => $o->request,
            'rfq_tag' => $o->rfq_tag,
            'cid' => $o->catalog_item_id,
            'sku' => $ci?->sku ?? $o->sku,
            'name' => $ci?->name,
            'supplier' => (string) ($o->supplier_name ?: $o->supplier_key),
            'source' => $o->source_type,
            'kind' => $o->kind ?? 'original',
            'product' => trim(($o->brand ?? '').' '.($o->part_number ?? '')),
            'review' => $o->review_status,
            'tiers' => (array) ($o->tiers ?? []),
            'price' => $o->price !== null ? (float) $o->price : null,
            'currency' => $o->currency,
            'rub' => $rub['known'] ? $rub['rub'] : null,
            'lead' => $o->lead_time_days,
            'valid' => $o->valid_until,
            'note' => $o->note,
            'stale' => $o->superseded || ! in_array($o->status, ['active', 'expiring'], true),
            'purchase' => $purchase,
            'sale' => $ci?->price !== null ? (float) $ci->price : null,
            'actual' => (bool) ($ci?->is_price_actual ?? false),
            // С закупкой не сравниваем: в ней логистика и таможня, цена поставщика — без них.
        ];
    }

    public function render()
    {
        if (! array_key_exists($this->view, self::VIEWS)) {
            $this->view = 'request';
        }

        return view('livewire.quoteparts.price-offers');
    }
}
