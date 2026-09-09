<?php

namespace App\Livewire\MailQuality;

use App\Enums\Role as RoleEnum;
use App\Services\Analytics\MailDecisionQualityService;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * «Качество почты» (/dashboard/mail-quality) — панель дрейфа решений
 * почтового конвейера: AI-решения по детекторам и доля отклонённых, ручные
 * откаты статуса назад, заявки-фантомы, куда уходят письма, понедельная
 * динамика. Доступ: РОП, директорат, админ. Агрегация — в
 * MailDecisionQualityService, здесь только период.
 */
class Index extends Component
{
    private const TZ = 'Europe/Moscow';

    #[Url(as: 'period', except: 30)]
    public int $periodDays = 30;

    public function mount(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole([
                RoleEnum::HeadOfSales->value,
                RoleEnum::Director->value,
                RoleEnum::Admin->value,
            ]),
            403,
            'Раздел «Качество почты» доступен РОПу, директорату и админам.',
        );
    }

    public function setPeriod(int $days): void
    {
        if (in_array($days, [7, 30, 90], true)) {
            $this->periodDays = $days;
            unset($this->report);
        }
    }

    #[Computed]
    public function periodLabel(): string
    {
        [$from, $to] = $this->range();

        return $from->format('d.m.Y') . ' – ' . $to->format('d.m.Y');
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function report(): array
    {
        [$from, $to] = $this->range();

        return app(MailDecisionQualityService::class)->report($from, $to);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(): array
    {
        $to = CarbonImmutable::now(self::TZ)->endOfDay();
        $from = $to->subDays($this->periodDays - 1)->startOfDay();

        return [$from, $to];
    }

    public function render()
    {
        return view('livewire.mail-quality.index');
    }
}
