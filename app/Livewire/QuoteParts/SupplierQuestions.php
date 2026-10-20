<?php

namespace App\Livewire\QuoteParts;

use App\Livewire\Concerns\AnswersQuotePartsSuppliers;
use App\Models\CatalogItem;
use App\Models\QuotePartsRfq;
use App\Services\QuoteParts\QuotePartsRfqService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Вкладка «Вопросы поставщиков» раздела QuoteParts: позиции, по которым поставщики задали
 * уточняющие вопросы (в запросе или из своей номенклатуры), — кто, что и когда спросил, и
 * переписка с ответом прямо здесь. Данные — снимки запросов QuoteParts (question_groups и
 * вопросы поставщиков без группы).
 */
class SupplierQuestions extends Component
{
    use AnswersQuotePartsSuppliers;
    use WithFileUploads;

    public const ANSWERED_DAYS = 14;

    /** Показывать и отвеченные (за ANSWERED_DAYS дней). */
    #[Url(as: 'qa', except: false)]
    public bool $withAnswered = false;

    /** Открытая переписка: «{id запроса}|{ключ поставщика}». */
    public ?string $dialog = null;

    /** @var list<int> раскрытые карточки позиций каталога */
    public array $openItems = [];

    public function openDialog(int $rfqId, string $supplierKey, QuotePartsRfqService $rfqs): void
    {
        $key = $rfqId.'|'.$supplierKey;
        $this->dialog = $this->dialog === $key ? null : $key;
        if ($this->dialog !== null) {
            try {
                $rfqs->refresh(QuotePartsRfq::query()->findOrFail($rfqId));
            } catch (Throwable) {
                // Покажем последний снимок.
            }
            unset($this->items);
        }
    }

    public function toggleItem(int $catalogItemId): void
    {
        $this->openItems = in_array($catalogItemId, $this->openItems, true)
            ? array_values(array_diff($this->openItems, [$catalogItemId]))
            : [...$this->openItems, $catalogItemId];
    }

    public function refreshQuotePartsRfq(int $id, QuotePartsRfqService $rfqs): void
    {
        try {
            $rfqs->refresh(QuotePartsRfq::query()->findOrFail($id));
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: 'QuoteParts не ответил: '.mb_substr($e->getMessage(), 0, 200));
        }
        unset($this->items);
    }

    protected function quotePartsRfqForAnswer(int $id): QuotePartsRfq
    {
        return QuotePartsRfq::query()->findOrFail($id);
    }

    protected function afterQuotePartsAnswer(): void
    {
        unset($this->items);
    }

    /**
     * Позиции с вопросами поставщиков: артикул => позиция, вопросы (поставщик, текст, когда,
     * запрос, заявка, отвечен ли). Сверху — где вопрос ждёт ответа дольше всего.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function items(): Collection
    {
        $since = now()->subDays(60);
        $rfqs = QuotePartsRfq::query()->with('request:id,internal_code')
            ->where(fn ($q) => $q->where('status', 'open')->orWhere('synced_at', '>=', now()->subDays(self::ANSWERED_DAYS)))
            ->where('created_at', '>=', $since)->get();
        $names = app(QuotePartsRfqService::class)->supplierNames($rfqs);

        $rows = collect();
        foreach ($rfqs as $rfq) {
            $snap = $rfq->snapshot ?? [];
            $lineCode = fn (string $supplier) => collect(collect($snap['suppliers'] ?? [])->firstWhere('external_id', $supplier)['lines'] ?? [])
                ->firstWhere('status', 'question')['code'] ?? null;
            $soleCode = count(collect($snap['suppliers'] ?? [])->flatMap(fn ($s) => collect($s['lines'] ?? [])->pluck('code'))->unique()) === 1
                ? ($snap['suppliers'][0]['lines'][0]['code'] ?? null) : null;

            // Вопросы, собранные в группы (одинаковые по смыслу): по позиции группы.
            foreach ((array) ($snap['question_groups'] ?? []) as $g) {
                foreach ((array) ($g['questions'] ?? []) as $q) {
                    $rows->push(array_merge($this->row($rfq, (string) ($q['supplier'] ?? ''), $g['item_code'] ?? $lineCode((string) ($q['supplier'] ?? '')) ?? $soleCode,
                        (string) ($q['text_ru'] ?: $q['text']), (string) $q['text'], $q['at'] ?? null, ($q['status'] ?? 'open') === 'open', $names), ['group_id' => (int) $g['id']]));
                }
            }
            // Вопросы без группы (письмом до группировки).
            foreach ((array) ($snap['suppliers'] ?? []) as $s) {
                foreach ((array) ($s['questions'] ?? []) as $q) {
                    if (! empty($q['group_id'])) {
                        continue;
                    }
                    $rows->push($this->row($rfq, (string) $s['external_id'], $lineCode((string) $s['external_id']) ?? $soleCode,
                        (string) (($q['text_ru'] ?? null) ?: $q['text']), (string) $q['text'], $q['at'] ?? null, true, $names));
                }
            }
        }

        $rows = $rows->when(! $this->withAnswered, fn ($c) => $c->where('open', true))
            ->filter(fn ($r) => $r['open'] || ($r['at'] && $r['at']->gte(now()->subDays(self::ANSWERED_DAYS))));
        $catalog = CatalogItem::query()->whereIn('sku', $rows->pluck('code')->filter()->unique())->get(['id', 'sku', 'name'])->keyBy('sku');

        return $rows->groupBy(fn ($r) => $r['code'] ?: 'rfq-'.$r['rfq']->id)
            ->map(fn (Collection $qs, string $key) => [
                'key' => $key,
                'code' => $qs->first()['code'],
                'cid' => $catalog->get((string) $qs->first()['code'])?->id,
                'name' => $catalog->get((string) $qs->first()['code'])?->name,
                'questions' => $qs->sortBy(fn ($r) => $r['at']?->getTimestamp() ?? 0)->values(),
                'open' => $qs->where('open', true)->count(),
                'oldest' => $qs->where('open', true)->min('at'),
            ])
            ->sortBy(fn ($i) => [$i['open'] > 0 ? 0 : 1, $i['oldest']?->getTimestamp() ?? PHP_INT_MAX])
            ->values();
    }

    /** @return array<string, mixed> */
    private function row(QuotePartsRfq $rfq, string $supplier, ?string $code, string $text, string $original, ?string $at, bool $open, $names): array
    {
        return [
            'rfq' => $rfq,
            'supplier_key' => $supplier,
            'supplier' => (string) ($names[$supplier] ?? $supplier),
            'code' => $code,
            'text' => $text,
            'original' => $original !== $text ? $original : null,
            'at' => $at ? Carbon::parse($at) : null,
            'open' => $open,
            'group_id' => null,
        ];
    }

    /** Раскрытые карточки позиций каталога. */
    #[Computed]
    public function openCards(): array
    {
        if ($this->openItems === []) {
            return [];
        }
        $pcs = \App\Models\CatalogPriceChange::query()->whereIn('catalog_item_id', $this->openItems)
            ->orderByDesc('changed_at')->orderByDesc('id')->get()->unique('catalog_item_id')->keyBy('catalog_item_id');

        return CatalogItem::query()->whereIn('id', $this->openItems)->get()->keyBy('id')
            ->map(fn ($c) => ['cat' => $c, 'pc' => $pcs->get($c->id)])->all();
    }

    public function render()
    {
        return view('livewire.quoteparts.supplier-questions');
    }
}
