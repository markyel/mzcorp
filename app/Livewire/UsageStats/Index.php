<?php

namespace App\Livewire\UsageStats;

use App\Enums\Role as RoleEnum;
use App\Services\Analytics\ManagerUsageStatsService;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Раздел «Использование системы» (/dashboard/usage) — статистика активности
 * менеджеров: время в системе, отправленные письма, сопоставления каталогу,
 * уточняющие вопросы. Доступ: директорат + админ.
 *
 * Вся агрегация — в ManagerUsageStatsService. Здесь только фильтры периода
 * и менеджеров (как в разделе «Аналитика»).
 */
class Index extends Component
{
    private const TZ = 'Europe/Moscow';

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
                RoleEnum::Director->value,
                RoleEnum::Admin->value,
            ]),
            403,
            'Раздел «Использование системы» доступен директорату и админам.',
        );
    }

    public function setPeriod(int $days): void
    {
        if (in_array($days, [7, 30, 90], true)) {
            $this->periodDays = $days;
            $this->customFrom = '';
            $this->customTo = '';
        }
    }

    public function applyCustomPeriod(): void
    {
        try {
            $from = CarbonImmutable::createFromFormat('Y-m-d', $this->customFrom, self::TZ);
            $to = CarbonImmutable::createFromFormat('Y-m-d', $this->customTo, self::TZ);
            if (! $from || ! $to || $from->gt($to)) {
                throw new \InvalidArgumentException('bad range');
            }
        } catch (\Throwable) {
            $this->customFrom = '';
            $this->customTo = '';
        }
    }

    public function clearCustomPeriod(): void
    {
        $this->customFrom = '';
        $this->customTo = '';
    }

    public function toggleManager(int $id): void
    {
        $idx = array_search($id, $this->managerIds, true);
        if ($idx !== false) {
            array_splice($this->managerIds, $idx, 1);
        } else {
            $this->managerIds[] = $id;
        }
    }

    public function clearManagers(): void
    {
        $this->managerIds = [];
    }

    #[Computed]
    public function isCustomPeriod(): bool
    {
        return $this->customFrom !== '' && $this->customTo !== '';
    }

    /**
     * Диапазон периода [начало дня, ИСКЛЮЧИТЕЛЬНО начало следующего дня], MSK.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function periodRange(): array
    {
        if ($this->isCustomPeriod) {
            try {
                $from = CarbonImmutable::createFromFormat('Y-m-d', $this->customFrom, self::TZ)->startOfDay();
                $to = CarbonImmutable::createFromFormat('Y-m-d', $this->customTo, self::TZ)->startOfDay()->addDay();

                return [$from, $to];
            } catch (\Throwable) {
                // fallthrough к пресету
            }
        }

        $todayStart = CarbonImmutable::now(self::TZ)->startOfDay();

        return [
            $todayStart->subDays(max(1, $this->periodDays) - 1),
            $todayStart->addDay(),
        ];
    }

    #[Computed]
    public function periodLabel(): string
    {
        [$from, $to] = $this->periodRange();
        $lastDay = $to->subDay();

        return $from->format('d.m.Y').' – '.$lastDay->format('d.m.Y');
    }

    #[Computed]
    public function managers()
    {
        return app(ManagerUsageStatsService::class)->managers();
    }

    /**
     * @return array{summary: array<int, array<string, mixed>>, daily: array<int, array<string, mixed>>}
     */
    #[Computed]
    public function report(): array
    {
        [$from, $to] = $this->periodRange();

        return app(ManagerUsageStatsService::class)->report($from, $to, $this->managerIds);
    }

    public function render()
    {
        return view('livewire.usage-stats.index');
    }
}
