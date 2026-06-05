<?php

namespace App\Livewire\Analytics;

use App\Enums\Role as RoleEnum;
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

    public function render()
    {
        return view('livewire.analytics.positions');
    }
}
