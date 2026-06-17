<?php

namespace App\Livewire\Analytics;

use App\Enums\Role as RoleEnum;
use App\Models\CatalogPriceChange;
use App\Models\IqotPosition;
use App\Services\Analytics\ManagerAnalyticsService;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Отчёт «Топ позиций»: самые продаваемые / самые отказные позиции каталога
 * на основе закрытых заявок (по дате закрытия). Топ-50 = первая страница;
 * «полный список» = пагинация; сортировка по продажам или по потерям.
 *
 * Доступ: head_of_sales / director / secretary / admin (как и аналитика).
 */
class Positions extends Component
{
    use WithPagination;

    /** Период по дате закрытия: 30 | 90 | 365 | all. */
    #[Url(as: 'period', except: '90')]
    public string $period = '90';

    /** Сортировка: won (по продажам) | lost (по потерям). */
    #[Url(as: 'sort', except: 'won')]
    public string $sort = 'won';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole([
                RoleEnum::HeadOfSales->value,
                RoleEnum::Director->value,
                RoleEnum::Secretary->value,
                RoleEnum::Admin->value,
            ]),
            403,
            'Раздел доступен РОПу, директорату, секретарю и админам.',
        );
    }

    public function setPeriod(string $p): void
    {
        $this->period = in_array($p, ['30', '90', '365', 'all'], true) ? $p : '90';
        $this->resetPage();
    }

    public function setSort(string $s): void
    {
        $this->sort = $s === 'lost' ? 'lost' : 'won';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(): array
    {
        $to = CarbonImmutable::now('Europe/Moscow');
        $from = match ($this->period) {
            '30' => $to->subDays(30),
            '365' => $to->subDays(365),
            'all' => CarbonImmutable::create(2000, 1, 1, 0, 0, 0, 'Europe/Moscow'),
            default => $to->subDays(90),
        };

        return [$from->startOfDay(), $to];
    }

    #[Computed]
    public function periodLabel(): string
    {
        return match ($this->period) {
            '30' => '30 дн.',
            '365' => 'год',
            'all' => 'всё время',
            default => '90 дн.',
        };
    }

    #[Computed]
    public function rows()
    {
        [$from, $to] = $this->range();

        return app(ManagerAnalyticsService::class)
            ->topPositionsQuery($from, $to, $this->sort, $this->search)
            ->paginate(50);
    }

    /**
     * Карта catalog_item_id → IqotPosition с отчётом (для показа сравнения цен
     * конкурентов прямо в списке, как в разделе IQOT). Только для позиций
     * текущей страницы.
     *
     * @return \Illuminate\Support\Collection<int, IqotPosition>
     */
    #[Computed]
    public function iqotByCatalogId()
    {
        $ids = collect($this->rows->items())->pluck('catalog_item_id')->filter()->all();
        if ($ids === []) {
            return collect();
        }

        return IqotPosition::with('catalogItem:id,sku,name,brand,brand_article,brands,articles,price,price_min,is_price_actual,lead_time_days')
            ->whereIn('catalog_item_id', $ids)
            ->whereNotNull('analyzed_at')
            ->whereNotNull('report')
            ->get()
            ->keyBy('catalog_item_id');
    }

    /**
     * Карта catalog_item_id → ПОСЛЕДНЕЕ изменение цены в выбранном периоде
     * (по changed_at). Сигнал «цена позиции менялась» прямо в списке. Только
     * для позиций текущей страницы и только с обеими ценами (было→стало).
     *
     * @return \Illuminate\Support\Collection<int, CatalogPriceChange>
     */
    #[Computed]
    public function priceChangeByCatalogId()
    {
        $ids = collect($this->rows->items())->pluck('catalog_item_id')->filter()->all();
        if ($ids === []) {
            return collect();
        }

        $cutoff = match ($this->period) {
            '30' => now()->subDays(30),
            '365' => now()->subDays(365),
            'all' => null,
            default => now()->subDays(90),
        };

        $base = CatalogPriceChange::query()
            ->whereIn('catalog_item_id', $ids)
            ->whereNotNull('old_price')
            ->whereNotNull('new_price');
        if ($cutoff !== null) {
            $base->where('changed_at', '>=', $cutoff);
        }

        // Последнее изменение на позицию (max id = самое свежее).
        $latestIds = (clone $base)
            ->selectRaw('max(id) as id')
            ->groupBy('catalog_item_id')
            ->pluck('id');
        if ($latestIds->isEmpty()) {
            return collect();
        }

        return CatalogPriceChange::query()
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('catalog_item_id');
    }

    public function render()
    {
        return view('livewire.analytics.positions');
    }
}
