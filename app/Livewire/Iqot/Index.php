<?php

namespace App\Livewire\Iqot;

use App\Enums\IqotPositionStatus;
use App\Jobs\Iqot\PollIqotSubmissionsJob;
use App\Models\IqotPosition;
use App\Services\Iqot\IqotDispatchService;
use App\Services\Iqot\IqotPoolService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Раздел «IQOT» (/dashboard/iqot) — пул анализа цен по позициям каталога.
 * Доступ: head_of_sales / director / admin (см. routes/web.php).
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'src', except: '')]
    public string $sourceFilter = '';

    #[Url(as: 'attn', except: false)]
    public bool $attentionOnly = false;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole(['head_of_sales', 'director', 'admin']),
            403,
            'Раздел «IQOT» доступен РОПу, директорату и админам.',
        );
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSourceFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAttentionOnly(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    private function freshDays(): int
    {
        return (int) app_setting('iqot.report_fresh_days', config('services.iqot.report_fresh_days', 90));
    }

    #[Computed]
    public function stats(): array
    {
        $byStatus = IqotPosition::query()
            ->selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $usedToday = IqotPosition::whereNotNull('last_enqueued_at')
            ->where('last_enqueued_at', '>=', now()->startOfDay())
            ->count();

        $fresh = IqotPosition::withFreshReport($this->freshDays())->count();

        $attention = IqotPosition::query()->needingPricingAttention()->count();
        $critical = IqotPosition::query()->criticalPricing()->count();

        $stuckCutoff = now()->subDays($this->stuckAnalyzingDays());
        $stuckAnalyzing = IqotPosition::query()
            ->where('status', IqotPositionStatus::Analyzing->value)
            ->where(fn ($q) => $q->whereNull('last_enqueued_at')->orWhere('last_enqueued_at', '<', $stuckCutoff))
            ->count();

        return [
            'enabled' => (bool) app_setting('iqot.enabled', config('services.iqot.enabled', false)),
            'configured' => trim((string) app_setting('iqot.api_key', config('services.iqot.api_key', ''))) !== '',
            'daily_limit' => (int) app_setting('iqot.daily_limit', config('services.iqot.daily_limit', 50)),
            'used_today' => $usedToday,
            'fresh' => $fresh,
            'attention' => $attention,
            'critical' => $critical,
            'stuck_analyzing' => $stuckAnalyzing,
            'stuck_days' => $this->stuckAnalyzingDays(),
            'by_status' => $byStatus,
            'total' => array_sum($byStatus),
            'mode' => (string) app_setting('iqot.mode', config('services.iqot.mode', 'monitoring')),
            'mon_active' => \App\Models\IqotMonitoringItem::query()->where('active', true)->count(),
            'mon_priced' => \App\Models\IqotMonitoringItem::query()->where('active', true)->whereNotNull('best_price')->count(),
            'mon_kp' => \App\Models\IqotMonitoringItem::query()->where('active', true)->where('has_kp', true)->count(),
            'mon_synced' => \App\Models\IqotMonitoringItem::query()->max('prices_synced_at'),
        ];
    }

    /**
     * Строка на позицию каталога: КП по заявкам IQOT (IqotPosition) и мониторинг цен «Гибрид»
     * (IqotMonitoringItem) вместе — цены рынка считает IqotMarket (без НДС, одним списком).
     */
    #[Computed]
    public function positions()
    {
        $page = \App\Models\CatalogItem::query()
            ->select('catalog_items.*')
            ->leftJoin('iqot_positions as p', 'p.catalog_item_id', '=', 'catalog_items.id')
            ->leftJoin('iqot_monitoring_items as m', 'm.catalog_item_id', '=', 'catalog_items.id')
            ->where(fn ($w) => $w->whereNotNull('p.id')->orWhereNotNull('m.id'))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('p.status', $this->statusFilter))
            // По умолчанию исключённые из заявок не показываем — для них есть фильтр (мониторинг при этом виден).
            ->when($this->statusFilter === '', fn ($q) => $q->where(fn ($w) => $w->whereNull('p.id')->orWhere('p.status', '!=', IqotPositionStatus::Excluded->value)->orWhereNotNull('m.id')))
            ->when(in_array($this->sourceFilter, ['auto', 'manual'], true), fn ($q) => $q->where('p.source', $this->sourceFilter))
            ->when($this->sourceFilter === 'monitoring', fn ($q) => $q->whereNotNull('m.id')->where('m.active', true))
            ->when($this->attentionOnly, fn ($q) => $q->whereIn('p.id', IqotPosition::query()->needingPricingAttention()->select('id')))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($c) => $c->where('catalog_items.name', 'ilike', $term)
                    ->orWhere('catalog_items.sku', 'ilike', $term)
                    ->orWhere('catalog_items.brand_article', 'ilike', $term));
            })
            // Критические («выше рынка» по заявкам) — наверх; затем позиции с ценами — свежие выше.
            ->orderByRaw(
                'CASE WHEN p.cmp_our_rank IS NOT NULL AND p.cmp_total IS NOT NULL AND p.cmp_total > 0 '
                .'AND (p.cmp_total - 1) >= ? AND (p.cmp_our_rank - 1)::float / p.cmp_total >= ? THEN 0 ELSE 1 END',
                [IqotPosition::criticalMinSuppliers(), 1 - IqotPosition::criticalTopPct() / 100],
            )
            ->orderByRaw('CASE WHEN m.best_price IS NOT NULL OR p.report IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByRaw('GREATEST(p.analyzed_at, m.prices_synced_at) DESC NULLS LAST')
            ->orderByDesc('catalog_items.id')
            ->paginate(40);

        $ids = $page->getCollection()->pluck('id')->all();
        $positions = IqotPosition::query()->with(['submission:id,submission_id,local_status', 'requestedBy:id,name'])
            ->whereIn('catalog_item_id', $ids)->get()->keyBy('catalog_item_id');
        $monitoring = \App\Models\IqotMonitoringItem::query()->whereIn('catalog_item_id', $ids)->get()->keyBy('catalog_item_id');
        $page->setCollection($page->getCollection()->map(function ($cat) use ($positions, $monitoring) {
            $pos = $positions->get($cat->id);
            $pos?->setRelation('catalogItem', $cat);
            $mon = $monitoring->get($cat->id);

            return (object) ['cat' => $cat, 'pos' => $pos, 'mon' => $mon, 'market' => \App\Services\Iqot\IqotMarket::for($cat, $pos, $mon)];
        }));

        return $page;
    }

    /** Мониторинг цен «Гибрид»: синхронизировать сейчас (то же, что ежечасный iqot:monitoring). */
    public function runMonitoring(\App\Services\Iqot\IqotMonitoringService $monitoring): void
    {
        $this->assertManager();
        $r = $monitoring->run();
        $items = $r['items'] ?? [];
        session()->flash('iqot-flash', isset($r['skipped']) ? 'Мониторинг IQOT выключен (режим или ключ в Настройках).'
            : (isset($r['error']) ? 'Мониторинг IQOT: '.$r['error']
                : 'Мониторинг IQOT синхронизирован: к актуализации с OEM — '.($items['wanted'] ?? 0).', отправлено изменений '.($items['sent'] ?? 0)
                    .', снято '.($items['deactivated'] ?? 0).'; цены обновлены по '.(is_int($r['prices'] ?? null) ? $r['prices'] : 0).' поз.'));
        unset($this->stats, $this->positions);
    }

    private function assertManager(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole(['head_of_sales', 'director', 'admin']),
            403,
        );
    }

    public function refreshPool(IqotPoolService $pool): void
    {
        $this->assertManager();
        $r = $pool->refreshPoolFromLostQuotes();
        $removed = $r['removed'] ?? 0;
        session()->flash('iqot-flash', "Пул обновлён: +{$r['created']} новых, {$r['updated']} обновлено, {$r['skipped_fresh']} со свежим отчётом"
            .($removed ? ", −{$removed} неквотированных убрано" : '').'.');
        unset($this->stats, $this->positions);
    }

    public function runDispatch(IqotDispatchService $dispatch): void
    {
        $this->assertManager();
        $r = $dispatch->dispatch();
        $msg = isset($r['submitted'])
            ? "Отправлено в IQOT: {$r['submitted']} позиц. (за сегодня: {$r['used_today']})."
            : 'IQOT: '.($r['skipped'] ?? $r['error'] ?? json_encode($r, JSON_UNESCAPED_UNICODE));
        session()->flash('iqot-flash', $msg);
        unset($this->stats, $this->positions);
    }

    public function runPoll(): void
    {
        $this->assertManager();
        PollIqotSubmissionsJob::dispatchSync(null);
        session()->flash('iqot-flash', 'Опрос IQOT выполнен — статусы обновлены.');
        unset($this->stats, $this->positions);
    }

    public function reanalyze(int $positionId, IqotPoolService $pool): void
    {
        $this->assertManager();
        $pos = IqotPosition::find($positionId);
        if ($pos) {
            $pool->enqueueCatalogItem((int) $pos->catalog_item_id, auth()->id());
            session()->flash('iqot-flash', 'Позиция поставлена в очередь на повторный анализ.');
            unset($this->stats, $this->positions);
        }
    }

    /**
     * Переотправить ЗАВИСШУЮ позицию на анализ: вернуть в очередь на отправку,
     * открепив от застрявшей submission. Нужно для позиций, застрявших в
     * `analyzing` (отправлены в IQOT, но отчёт так и не пришёл) — обычный
     * `reanalyze`/`enqueueCatalogItem` их НЕ трогает (гард «не сбрасываем, если
     * уже отправлена»). Следующий dispatch (крон каждые 2ч или «Отправить
     * сейчас») отправит заново.
     */
    public function resendAnalysis(int $positionId): void
    {
        $this->assertManager();
        $pos = IqotPosition::find($positionId);
        if (! $pos || ! in_array($pos->status, [
            IqotPositionStatus::Analyzing->value,
            IqotPositionStatus::Failed->value,
        ], true)) {
            return;
        }
        $pos->forceFill([
            'status' => IqotPositionStatus::Pending->value,
            'iqot_submission_id' => null,
            'iqot_item_status' => null,
            'error_code' => null,
            'error_message' => null,
            'source' => IqotPosition::SOURCE_MANUAL,
            'requested_by_user_id' => auth()->id(),
            'manual_requested_at' => now(),
        ])->save();
        session()->flash('iqot-flash', 'Позиция возвращена в очередь на отправку — уйдёт при ближайшем отправлении (кнопка «Отправить сейчас» или автоотправка).');
        unset($this->stats, $this->positions);
    }

    /** Порог «зависла в анализе» (дней с last_enqueued_at). */
    private function stuckAnalyzingDays(): int
    {
        return (int) app_setting('iqot.stuck_analyzing_days', 2);
    }

    /**
     * Массовая переотправка ВСЕХ зависших в анализе позиций (analyzing +
     * last_enqueued_at старше порога, либо без даты). Возвращает их в очередь
     * (pending), открепляя от застрявших submission. Кейс: IQOT был на паузе →
     * сотни позиций осиротели в analyzing. Отправятся партиями по дневному лимиту.
     */
    public function resendAllStuck(): void
    {
        $this->assertManager();
        $cutoff = now()->subDays($this->stuckAnalyzingDays());
        $n = IqotPosition::query()
            ->where('status', IqotPositionStatus::Analyzing->value)
            ->where(fn ($q) => $q->whereNull('last_enqueued_at')->orWhere('last_enqueued_at', '<', $cutoff))
            ->update([
                'status' => IqotPositionStatus::Pending->value,
                'iqot_submission_id' => null,
                'iqot_item_status' => null,
                'error_code' => null,
                'error_message' => null,
            ]);
        session()->flash('iqot-flash', "Возвращено в очередь зависших позиций: {$n}. Отправятся партиями по дневному лимиту (крон каждые 2ч или «Отправить сейчас»).");
        unset($this->stats, $this->positions);
    }

    /**
     * Исключить позицию из пула навсегда («не запрашивать никогда»).
     */
    public function exclude(int $positionId): void
    {
        $this->assertManager();
        $pos = IqotPosition::find($positionId);
        if ($pos) {
            $pos->forceFill([
                'status' => IqotPositionStatus::Excluded->value,
                'excluded_at' => now(),
                'excluded_by_user_id' => auth()->id(),
            ])->save();
            session()->flash('iqot-flash', 'Позиция исключена из пула — больше не запрашивается.');
            unset($this->stats, $this->positions);
        }
    }

    /**
     * Вернуть исключённую позицию обратно в пул.
     */
    public function unexclude(int $positionId): void
    {
        $this->assertManager();
        $pos = IqotPosition::find($positionId);
        if ($pos && $pos->isExcluded()) {
            $pos->forceFill([
                'status' => IqotPositionStatus::Pending->value,
                'excluded_at' => null,
                'excluded_by_user_id' => null,
                'error_code' => null,
                'error_message' => null,
            ])->save();
            session()->flash('iqot-flash', 'Позиция возвращена в пул.');
            unset($this->stats, $this->positions);
        }
    }

    public function render()
    {
        return view('livewire.iqot.index');
    }
}
