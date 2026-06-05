<?php

namespace App\Livewire\Iqot;

use App\Enums\IqotPositionStatus;
use App\Models\IqotPosition;
use App\Services\Iqot\IqotDispatchService;
use App\Services\Iqot\IqotPoolService;
use App\Jobs\Iqot\PollIqotSubmissionsJob;
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

        return [
            'enabled' => (bool) app_setting('iqot.enabled', config('services.iqot.enabled', false)),
            'configured' => trim((string) app_setting('iqot.api_key', config('services.iqot.api_key', ''))) !== '',
            'daily_limit' => (int) app_setting('iqot.daily_limit', config('services.iqot.daily_limit', 50)),
            'used_today' => $usedToday,
            'fresh' => $fresh,
            'by_status' => $byStatus,
            'total' => array_sum($byStatus),
        ];
    }

    #[Computed]
    public function positions()
    {
        return IqotPosition::query()
            ->with(['catalogItem:id,sku,name,brand,brand_article,brands,articles', 'submission:id,submission_id,local_status', 'requestedBy:id,name'])
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->sourceFilter !== '', fn ($q) => $q->where('source', $this->sourceFilter))
            ->when($this->search !== '', function ($q) {
                $term = '%' . $this->search . '%';
                $q->whereHas('catalogItem', function ($c) use ($term) {
                    $c->where('name', 'ilike', $term)
                        ->orWhere('sku', 'ilike', $term)
                        ->orWhere('brand_article', 'ilike', $term);
                });
            })
            ->orderByRaw('CASE WHEN source = ? THEN 0 ELSE 1 END', [IqotPosition::SOURCE_MANUAL])
            ->orderByDesc('lost_quote_count')
            ->orderByDesc('analyzed_at')
            ->orderByDesc('id')
            ->paginate(40);
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
        session()->flash('iqot-flash', "Пул обновлён: +{$r['created']} новых, {$r['updated']} обновлено, {$r['skipped_fresh']} со свежим отчётом.");
        unset($this->stats, $this->positions);
    }

    public function runDispatch(IqotDispatchService $dispatch): void
    {
        $this->assertManager();
        $r = $dispatch->dispatch();
        $msg = isset($r['submitted'])
            ? "Отправлено в IQOT: {$r['submitted']} позиц. (за сегодня: {$r['used_today']})."
            : 'IQOT: ' . ($r['skipped'] ?? $r['error'] ?? json_encode($r, JSON_UNESCAPED_UNICODE));
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

    public function render()
    {
        return view('livewire.iqot.index');
    }
}
