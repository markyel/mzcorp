<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| --------------------------------------------------------------------------
| MyLift scheduled tasks
| --------------------------------------------------------------------------
*/

// Foundation §1: «Старт — polling каждые 1-2 минуты». Идём с 2 минутами.
// withoutOverlapping предотвращает накладку, если предыдущий запуск ещё идёт.
Schedule::command('mail:sync')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
