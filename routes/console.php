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

// Recovery нераспределённых заявок. Если в окне деплоя AssignmentService
// pipeline persist() упал между сохранением items и autoAssign'ом — Request
// остаётся Pending+unassigned, в пулах не виден. Hourly прогон находит такие
// зомби и: (a) запускает autoAssign если items есть, (b) закрывает как
// ParserNoContent если пусто >2ч. См. RequestsRecoverUnassignedCommand.
Schedule::command('requests:recover-unassigned')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Регулярный pull MDB-каталога с public URL (mylift.ru/getxfile.php).
// Источник обновляется ~2 раза в день. Расписание заказчика: первый
// pull в 11:00, дальше каждые 4 часа → 11, 15, 19, 23, 03, 07 MSK.
// Команда сама HEAD-checks Last-Modified + SHA-256 — при unchanged-snapshot
// выходит rapid'но без import'а, не нагружает БД на каждый прогон.
Schedule::command('catalog:sync-from-url')
    ->cron('0 3,7,11,15,19,23 * * *')
    ->timezone('Europe/Moscow')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Embeddings новых/изменившихся каталожных позиций. Инкрементально —
// проверяет hash против catalog_item_embeddings и эмбеддит только дельту.
// Запускаем через 5 минут после каждого slot'а catalog:sync-from-url,
// чтобы только что импортированные SKU оказывались в vector-индексе.
// Без этого vector-поиск на свежих позициях не работает (см. 2026-05-21
// кейс M33763: добавлен вчера, embedding ОТСУТСТВУЕТ, найти можно было
// только через code/trgm, не через семантику).
Schedule::command('catalog:embed')
    ->cron('5 3,7,11,15,19,23 * * *')
    ->timezone('Europe/Moscow')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Phase 4: ежедневно в 07:00 проверяем просроченные счета. По истечении
// expires_at счёт → expired, Request возвращается в AwaitingInvoice
// (если у него нет других pending invoices — для re-issue). См.
// InvoicesCheckExpiryCommand + Services/Invoices/InvoiceService::expire.
Schedule::command('invoices:check-expiry')
    ->dailyAt('07:00')
    ->timezone('Europe/Moscow')
    ->withoutOverlapping()
    ->onOneServer();
