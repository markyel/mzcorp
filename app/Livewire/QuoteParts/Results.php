<?php

namespace App\Livewire\QuoteParts;

use App\Services\QuoteParts\PriceDynamicsService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * «Результаты» раздела QuoteParts (12.11.2026): доля позиций прайса с актуальной ценой по дням (в наличии /
 * под заказ) и выбранные цены поставщиков против прошлых закупок по 1С — по неделям (PriceDynamicsService).
 */
class Results extends Component
{
    #[Computed]
    public function actuality(): array
    {
        return app(PriceDynamicsService::class)->actuality(180);
    }

    #[Computed]
    public function purchase(): array
    {
        return app(PriceDynamicsService::class)->purchaseTrend(26);
    }

    public function render()
    {
        return view('livewire.quoteparts.results');
    }
}
