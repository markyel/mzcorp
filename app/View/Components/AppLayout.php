<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * @param  bool  $rail  Обернуть main в grid с левым thin-rail
     *                       (см. resources/views/components/left-rail.blade.php).
     *                       По умолчанию true — rail виден на всех auth-страницах.
     *                       Передать false для страниц которые сами строят
     *                       свой grid с rail внутри (pool, catalog.search,
     *                       requests.show).
     * @param  string|null  $railActive  Ключ активного раздела для подсветки
     *                       пункта в rail. Если null — auto-detect по route name
     *                       (см. blade-шаблон).
     */
    public function __construct(
        public bool $rail = true,
        public ?string $railActive = null,
    ) {
    }

    public function render(): View
    {
        return view('layouts.app');
    }
}
