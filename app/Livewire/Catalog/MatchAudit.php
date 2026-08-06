<?php

namespace App\Livewire\Catalog;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * «Аудит матчинга» (для руководителей): расхождения авто-матча позиций с тем,
 * что менеджер реально указал в отправленном КП. Строка КП несёт SKU из PDF
 * (истина), поэтому если `request_items.catalog_item_id` (авто-матч) != каталогу
 * строки КП по той же позиции — это кандидат в ложный матч. Список для контроля
 * качества матчинга + быстрая правка (переставить каталог позиции на КП-шный).
 */
class MatchAudit extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /** Скрывать легитимные замены/аналоги (is_analog / «ЗАМЕНЕНО НА…»). */
    #[Url(as: 'hidesub')]
    public bool $hideSubstitutions = true;

    // Полное сравнение 2 каталогов (система vs КП) по позиции — переиспользует
    // CatalogComparisonService (как «Похожее из каталога»).
    public ?int $compareRequestItemId = null;

    public ?int $compareSysCatId = null;

    public ?int $compareKpCatId = null;

    public bool $comparing = false;

    private const PER_PAGE = 40;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedHideSubstitutions(): void
    {
        $this->resetPage();
    }

    /**
     * Базовый запрос расхождений: авто-каталог позиции != каталог строки КП.
     */
    private function baseQuery()
    {
        $q = DB::table('outbound_quote_items as oqi')
            ->join('outbound_quotes as oq', 'oq.id', '=', 'oqi.outbound_quote_id')
            ->join('request_items as ri', 'ri.id', '=', 'oqi.matched_request_item_id')
            ->join('requests as r', 'r.id', '=', 'oq.request_id')
            ->leftJoin('catalog_items as kpc', 'kpc.id', '=', 'oqi.matched_catalog_item_id')
            ->leftJoin('catalog_items as sysc', 'sysc.id', '=', 'ri.catalog_item_id')
            ->whereNotNull('ri.catalog_item_id')
            ->whereNotNull('oqi.matched_catalog_item_id')
            ->whereColumn('ri.catalog_item_id', '<>', 'oqi.matched_catalog_item_id')
            ->where('ri.is_active', true);

        if ($this->hideSubstitutions) {
            $q->where('oqi.is_analog', false)
                ->where(fn ($w) => $w->whereNull('sysc.name')->orWhere('sysc.name', 'not ilike', '%ЗАМЕНЕНО%'))
                ->where(fn ($w) => $w->whereNull('oqi.raw_name')->orWhere('oqi.raw_name', 'not ilike', '%аналог%'));
        }

        $s = trim($this->search);
        if ($s !== '') {
            $q->where(function ($w) use ($s) {
                $w->where('r.internal_code', 'ilike', '%'.$s.'%')
                    ->orWhere('ri.parsed_name', 'ilike', '%'.$s.'%')
                    ->orWhere('sysc.sku', 'ilike', '%'.$s.'%')
                    ->orWhere('kpc.sku', 'ilike', '%'.$s.'%');
            });
        }

        return $q;
    }

    #[Computed]
    public function total(): int
    {
        return (clone $this->baseQuery())->count();
    }

    #[Computed]
    public function rows()
    {
        return $this->baseQuery()
            ->select([
                'oqi.id as oqi_id',
                'oqi.matched_request_item_id as request_item_id',
                'oqi.matched_catalog_item_id as kp_catalog_id',
                'ri.catalog_item_id as sys_catalog_id',
                'oqi.is_analog',
                'oqi.raw_name as kp_raw_name',
                'oqi.raw_article as kp_raw_article',
                'oq.document_number as kp_no',
                'r.id as request_id',
                'r.internal_code',
                'ri.position',
                'ri.parsed_name',
                'ri.parsed_article',
                'sysc.sku as sys_sku',
                'sysc.name as sys_name',
                'kpc.sku as kp_sku',
                'kpc.name as kp_name',
            ])
            ->orderByDesc('r.internal_code')
            ->orderBy('ri.position')
            ->paginate(self::PER_PAGE);
    }

    /**
     * Исправить: переставить каталог позиции заявки на тот, что в КП (ручной
     * выбор менеджера = истина). После этого строка уходит из списка.
     */
    public function applyKpCatalog(int $requestItemId, int $kpCatalogId): void
    {
        $item = \App\Models\RequestItem::find($requestItemId);
        $cat = \App\Models\CatalogItem::find($kpCatalogId);
        if ($item === null || $cat === null) {
            $this->dispatch('toast', message: 'Позиция или каталог не найдены.', type: 'error');

            return;
        }

        $item->forceFill([
            'catalog_item_id' => $cat->id,
            'quality_assessment_status' => 'sufficient',
        ])->save();

        \Illuminate\Support\Facades\Log::info('MatchAudit: catalog reassigned from КП', [
            'request_item_id' => $item->id,
            'old_catalog' => $item->getOriginal('catalog_item_id'),
            'new_catalog' => $cat->id,
            'by_user' => auth()->id(),
        ]);

        unset($this->rows, $this->total);
        $this->dispatch('toast', message: "Позиция привязана к {$cat->sku} (как в КП).", type: 'success');
    }

    // ───────── Полное сравнение (система vs КП) ─────────

    public function openCompare(int $requestItemId, int $sysCatId, int $kpCatId): void
    {
        $this->compareRequestItemId = $requestItemId;
        $this->compareSysCatId = $sysCatId;
        $this->compareKpCatId = $kpCatId;
        $this->comparing = true;
    }

    public function closeCompare(): void
    {
        $this->comparing = false;
        $this->compareRequestItemId = null;
        $this->compareSysCatId = null;
        $this->compareKpCatId = null;
    }

    #[Computed]
    public function compareSubject(): ?\App\Models\RequestItem
    {
        return $this->compareRequestItemId
            ? \App\Models\RequestItem::with(['brand', 'kbCategory'])->find($this->compareRequestItemId)
            : null;
    }

    /**
     * Данные compare-таблицы: позиция заявки vs [система, КП]. Тот же сервис,
     * что и в диалоге «Похожее из каталога».
     */
    #[Computed]
    public function comparisonData(): ?array
    {
        if (! $this->comparing || $this->compareSubject === null) {
            return null;
        }
        $sys = \App\Models\CatalogItem::find($this->compareSysCatId);
        $kp = \App\Models\CatalogItem::find($this->compareKpCatId);
        $candidates = collect(array_values(array_filter([$sys, $kp])));
        if ($candidates->isEmpty()) {
            return null;
        }

        try {
            return app(\App\Services\Catalog\CatalogComparisonService::class)
                ->compare($this->compareSubject, $candidates);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('MatchAudit: compare failed', [
                'request_item_id' => $this->compareRequestItemId,
                'sys' => $this->compareSysCatId,
                'kp' => $this->compareKpCatId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function render()
    {
        return view('livewire.catalog.match-audit');
    }
}
