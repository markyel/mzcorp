<?php

namespace App\Livewire\QuoteParts;

use App\Models\QuotePartsOffer;
use App\Services\Iqot\IqotCurrencyConverter;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Вкладка «Актуализация цен» раздела QuoteParts: свежие предложения поставщиков из QuoteParts
 * (форма и письмо по запросу, кабинет, прайс) рядом с ценами каталога — закупочной и продажной.
 * Ответы на запросы по заявкам можно собрать по заявкам; остальное — блок «Без заявки».
 */
class PriceOffers extends Component
{
    public const DAYS = [3, 7, 30, 90];

    #[Url(as: 'pd', except: 7)]
    public int $days = 7;

    /** Группировать по заявкам. */
    #[Url(as: 'pg', except: true)]
    public bool $byRequest = true;

    /** Показывать и заменённые / истёкшие предложения. */
    #[Url(as: 'pa', except: false)]
    public bool $withStale = false;

    #[Url(as: 'pq', except: '')]
    public string $search = '';

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function rows(): Collection
    {
        $since = now()->subDays(in_array($this->days, self::DAYS, true) ? $this->days : 7);
        $term = trim($this->search);

        return QuotePartsOffer::query()
            ->with(['catalogItem:id,sku,name,brand,price,purchase_price,is_price_actual', 'request:id,internal_code,client_name,subject,assigned_user_id', 'request.assignedUser:id,name'])
            ->where(fn ($q) => $q->where('remote_updated_at', '>=', $since)->orWhere('created_at', '>=', $since))
            ->when(! $this->withStale, fn ($q) => $q->where('superseded', false)->whereIn('status', ['active', 'expiring']))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('sku', 'ilike', '%'.$term.'%')
                ->orWhere('supplier_name', 'ilike', '%'.$term.'%')
                ->orWhere('part_number', 'ilike', '%'.$term.'%')
                ->orWhere('rfq_tag', 'ilike', '%'.$term.'%')
                ->orWhereHas('catalogItem', fn ($c) => $c->where('name', 'ilike', '%'.$term.'%'))
                ->orWhereHas('request', fn ($r) => $r->where('internal_code', 'ilike', '%'.$term.'%'))))
            ->orderByDesc('remote_updated_at')
            ->limit(500)
            ->get()
            ->map(fn (QuotePartsOffer $o) => self::row($o));
    }

    /**
     * Группы для показа: по заявке (свежий ответ — выше), «Без заявки» — последней.
     *
     * @return Collection<int, array{key:string, request:?\App\Models\Request, rfqs:list<string>, rows:Collection, at:mixed}>
     */
    #[Computed]
    public function groups(): Collection
    {
        if (! $this->byRequest) {
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
            // Насколько оффер (в ₽) отличается от закупочной цены каталога.
            'delta_pct' => $rub['known'] && $rub['rub'] !== null && $purchase !== null && $purchase > 0 ? ($rub['rub'] - $purchase) / $purchase * 100 : null,
        ];
    }

    public function render()
    {
        return view('livewire.quoteparts.price-offers');
    }
}
