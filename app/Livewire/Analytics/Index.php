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
 * Раздел «Аналитика» (/dashboard/analytics) — метрики по менеджерам.
 *
 * Доступ: head_of_sales / director / secretary / admin (как и дашборд-аналитика).
 * Вся агрегация — в ManagerAnalyticsService (тот же источник, что у виджетов
 * дашборда).
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'period', except: 30)]
    public int $periodDays = 30;

    #[Url(as: 'from', except: '')]
    public string $customFrom = '';

    #[Url(as: 'to', except: '')]
    public string $customTo = '';

    /** Фильтр по менеджерам (пусто = все). @var array<int, int> */
    #[Url(as: 'mgr')]
    public array $managerIds = [];

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
            'Раздел «Аналитика» доступен РОПу, директорату, секретарю и админам.',
        );
    }

    public function setPeriod(int $days): void
    {
        if (in_array($days, [7, 30, 90], true)) {
            $this->periodDays = $days;
            $this->customFrom = '';
            $this->customTo = '';
            $this->resetPage();
        }
    }

    public function applyCustomPeriod(): void
    {
        try {
            $from = CarbonImmutable::createFromFormat('Y-m-d', $this->customFrom, 'Europe/Moscow');
            $to = CarbonImmutable::createFromFormat('Y-m-d', $this->customTo, 'Europe/Moscow');
            if (! $from || ! $to || $from->gt($to)) {
                throw new \InvalidArgumentException('bad range');
            }
            $this->resetPage();
        } catch (\Throwable) {
            $this->customFrom = '';
            $this->customTo = '';
        }
    }

    public function clearCustomPeriod(): void
    {
        $this->customFrom = '';
        $this->customTo = '';
        $this->resetPage();
    }

    public function toggleManager(int $id): void
    {
        $idx = array_search($id, $this->managerIds, true);
        if ($idx !== false) {
            array_splice($this->managerIds, $idx, 1);
        } else {
            $this->managerIds[] = $id;
        }
        $this->resetPage();
    }

    public function clearManagers(): void
    {
        $this->managerIds = [];
        $this->resetPage();
    }

    #[Computed]
    public function isCustomPeriod(): bool
    {
        return $this->customFrom !== '' && $this->customTo !== '';
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function periodRange(): array
    {
        if ($this->isCustomPeriod) {
            try {
                $from = CarbonImmutable::createFromFormat('Y-m-d', $this->customFrom, 'Europe/Moscow')->startOfDay();
                $to = CarbonImmutable::createFromFormat('Y-m-d', $this->customTo, 'Europe/Moscow')->endOfDay();

                return [$from, $to];
            } catch (\Throwable) {
                // fallthrough
            }
        }

        return [
            CarbonImmutable::now('Europe/Moscow')->subDays($this->periodDays)->startOfDay(),
            CarbonImmutable::now('Europe/Moscow'),
        ];
    }

    #[Computed]
    public function periodLabel(): string
    {
        if ($this->isCustomPeriod) {
            try {
                $from = CarbonImmutable::createFromFormat('Y-m-d', $this->customFrom);
                $to = CarbonImmutable::createFromFormat('Y-m-d', $this->customTo);

                return $from->format('d.m') . ' – ' . $to->format('d.m.Y');
            } catch (\Throwable) {
                return 'произвольный';
            }
        }

        return $this->periodDays . ' дн.';
    }

    #[Computed]
    public function managers()
    {
        return app(ManagerAnalyticsService::class)->managers();
    }

    #[Computed]
    public function dynamics(): array
    {
        [$from, $to] = $this->periodRange();

        return app(ManagerAnalyticsService::class)->closedDynamics($from, $to, $this->managerIds);
    }

    #[Computed]
    public function wonLost(): array
    {
        [$from, $to] = $this->periodRange();

        return app(ManagerAnalyticsService::class)->wonLostByManager($from, $to, $this->managerIds);
    }

    #[Computed]
    public function timeToClose(): array
    {
        [$from, $to] = $this->periodRange();

        return app(ManagerAnalyticsService::class)->timeToCloseByManager($from, $to, $this->managerIds);
    }

    #[Computed]
    public function details()
    {
        [$from, $to] = $this->periodRange();

        return app(ManagerAnalyticsService::class)
            ->requestDetailsQuery($from, $to, $this->managerIds)
            ->paginate(40);
    }

    public function render()
    {
        return view('livewire.analytics.index');
    }
}
