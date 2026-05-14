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

// Phase 1.10 (Foundation §5.4): возобновление paused-заявок чьи
// paused_until <= now(). Один раз утром — оператор увидит «оттаявшие»
// заявки в начале рабочего дня.
Schedule::command('requests:resume-paused')
    ->dailyAt('06:00')
    ->onOneServer();

// Phase 1.11 (Foundation §5.3): денормализованный sweep `attention_level`
// для overdue-подсветки в Pool. Каждые 15 минут — компромисс между
// точностью и нагрузкой; для просрочек в часах/днях этого достаточно.
Schedule::command('requests:check-attention')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Foundation Фаза 2: применение запланированных отсутствий — open
// активные заявки коллегам в момент наступления unavailable_from
// (если РОП поставил флаг auto_delegate). Hourly даёт max 1 час
// отставания от момента «с».
Schedule::command('users:apply-planned-unavailability')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
