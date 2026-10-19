<?php

namespace App\Livewire\Procurement;

use App\Models\QuotePartsOffer;
use App\Services\Procurement\SupplierChoiceService;
use App\Services\QuoteParts\QuotePartsPriceService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * «Снабжение» → «Предложения поставщиков»: все новые цены поставщиков одной лентой, свежие
 * сверху — ответы на рассылку (письма) и предложения через QuoteParts (форма запроса, кабинет,
 * прайс). Отсюда же: разобрать отклонение (аналог, копия, восстановленное) и выбрать, у кого
 * закупаем позицию.
 */
class SupplierOffersFeed extends Component
{
    use WithPagination;

    public const PER_PAGE = 50;

    #[Url(as: 'od', except: 7)]
    public int $days = 7;

    /** '' — все, quoteparts, email */
    #[Url(as: 'os', except: '')]
    public string $source = '';

    #[Url(as: 'oq', except: '')]
    public string $search = '';

    /** Только отклонения, ждущие разбора. */
    #[Url(as: 'or', except: false)]
    public bool $toReview = false;

    public function updating(string $name): void
    {
        $this->resetPage('op');
    }

    public function accept(int $id, QuotePartsPriceService $prices): void
    {
        $prices->review(QuotePartsOffer::query()->findOrFail($id), true, auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Принято к закупке: цена идёт как цена позиции.');
    }

    public function reject(int $id, QuotePartsPriceService $prices): void
    {
        $prices->review(QuotePartsOffer::query()->findOrFail($id), false, auth()->user());
    }

    /** «Закупаем здесь» по строке ленты (ключ поставщика и цена — из этой строки). */
    public function choose(string $rowKey, SupplierChoiceService $choices): void
    {
        $row = $this->rows()->firstWhere('row', $rowKey);
        abort_if($row === null || $row['key'] === null || $row['cid'] === null, 404);
        $choices->choose($row['cid'], $row['key'], ['supplier' => $row['supplier'], 'price' => $row['price'], 'currency' => $row['currency'], 'source' => $row['source'], 'ref' => $row['ref']], auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Закупаем '.$row['sku'].' у: '.$row['supplier'].'.');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        $since = now()->subDays(max(1, $this->days));
        $rows = collect();

        if ($this->source !== 'email') {
            $qp = QuotePartsOffer::query()->with(['catalogItem:id,sku,name', 'request:id,internal_code'])
                ->where(fn ($q) => $q->where('remote_updated_at', '>=', $since)->orWhere('created_at', '>=', $since))
                ->when($this->toReview, fn ($q) => $q->where('review_status', QuotePartsOffer::REVIEW_PENDING))
                ->get();
            $sources = ['form' => 'форма запроса', 'email' => 'письмо', 'cabinet' => 'кабинет', 'price_list' => 'прайс', 'api' => 'API'];
            foreach ($qp as $o) {
                $rows->push([
                    'row' => 'qp-'.$o->id, 'at' => $o->remote_updated_at ?? $o->created_at,
                    'cid' => $o->catalog_item_id, 'sku' => $o->catalogItem?->sku ?? $o->sku, 'name' => $o->catalogItem?->name,
                    'supplier' => (string) ($o->supplier_name ?: $o->supplier_key), 'key' => $o->supplier_key,
                    'channel' => 'QuoteParts · '.($sources[$o->source_type] ?? $o->source_type),
                    'kind' => $o->kind ?? 'original', 'product' => trim(($o->brand ?? '').' '.($o->part_number ?? '')),
                    'review' => $o->review_status, 'note' => $o->note, 'photos' => (int) $o->photos, 'remote_id' => $o->remote_id,
                    'price' => $o->price !== null ? (float) $o->price : null, 'currency' => $o->currency,
                    'lead' => $o->lead_time_days, 'valid' => $o->valid_until?->format('d.m.Y'),
                    'stale' => $o->superseded || ! in_array($o->status, ['active', 'expiring'], true),
                    'source' => 'quoteparts', 'ref' => $o->id, 'request' => $o->request?->internal_code, 'request_id' => $o->request_id, 'outcome' => 'quoted',
                ]);
            }
        }

        if ($this->source !== 'quoteparts' && ! $this->toReview) {
            $mail = DB::table('supplier_offers as so')
                ->join('supplier_inquiry_items as sii', 'sii.id', '=', 'so.supplier_inquiry_item_id')
                ->join('supplier_inquiries as si', 'si.id', '=', 'sii.supplier_inquiry_id')
                ->leftJoin('request_items as ri', 'ri.id', '=', 'sii.request_item_id')
                ->leftJoin('catalog_items as ci', 'ci.id', '=', DB::raw('coalesce(sii.catalog_item_id, ri.catalog_item_id)'))
                ->leftJoin('email_messages as em', 'em.id', '=', 'so.email_message_id')
                ->leftJoin('requests as r', 'r.id', '=', 'si.related_request_id')
                ->where('so.outcome', 'quoted')
                ->where(DB::raw('coalesce(em.sent_at, so.created_at)'), '>=', $since)
                ->get(['so.id', 'so.price', 'so.currency', 'so.valid_until_text', 'so.raw_quote', 'si.supplier_name', 'si.supplier_email',
                    'ci.id as cid', 'ci.sku', DB::raw('coalesce(ci.name, sii.item_name) as name'), 'r.internal_code', 'r.id as request_id',
                    DB::raw('coalesce(em.sent_at, so.created_at) as at')]);
            $keys = SupplierChoiceService::keysByEmail($mail->pluck('supplier_email'));
            foreach ($mail as $m) {
                $rows->push([
                    'row' => 'mail-'.$m->id, 'at' => Carbon::parse($m->at),
                    'cid' => $m->cid ? (int) $m->cid : null, 'sku' => $m->sku, 'name' => $m->name,
                    'supplier' => (string) ($m->supplier_name ?: $m->supplier_email), 'key' => $keys[mb_strtolower(trim((string) $m->supplier_email))] ?? null,
                    'channel' => 'письмо', 'kind' => 'original', 'product' => '', 'review' => null, 'note' => $m->raw_quote ? mb_substr((string) $m->raw_quote, 0, 160) : null,
                    'photos' => 0, 'remote_id' => null,
                    'price' => $m->price !== null ? (float) $m->price : null, 'currency' => $m->currency ?: 'RUB',
                    'lead' => null, 'valid' => $m->valid_until_text, 'stale' => false,
                    'source' => 'email', 'ref' => (int) $m->id, 'request' => $m->internal_code, 'request_id' => $m->request_id, 'outcome' => 'quoted',
                ]);
            }
        }

        $term = mb_strtolower(trim($this->search));
        if ($term !== '') {
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['sku'].' '.$r['name'].' '.$r['supplier'].' '.$r['product']), $term));
        }

        return $rows->sortByDesc(fn ($r) => $r['at']?->getTimestamp() ?? 0)->values();
    }

    public function render(SupplierChoiceService $choices)
    {
        $rows = $this->rows();
        $page = max(1, (int) $this->getPage('op'));
        $paged = new LengthAwarePaginator($rows->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(), $rows->count(), self::PER_PAGE, $page, ['pageName' => 'op']);
        $chosen = $choices->forItems($paged->getCollection()->pluck('cid')->filter()->unique()->values()->all());

        return view('livewire.procurement.supplier-offers-feed', [
            'offers' => $paged,
            'chosen' => $chosen,
            'pendingReview' => QuotePartsOffer::query()->where('review_status', QuotePartsOffer::REVIEW_PENDING)->where('superseded', false)->count(),
        ]);
    }
}
