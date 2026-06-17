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

// Self-healing: добить boost для AiDecision'ов, чьи OutboundQuote
// успешно сматчили позиции, но boost не сработал (race-condition с
// recordSuggestion, OpenAI-fail во время ParseOutboundQuoteJob и т.п.).
// См. App\Console\Commands\QuotesReboostStuckDecisionsCommand —
// кейс M-2026-1558.
Schedule::command('quotes:reboost-stuck-decisions --apply --limit=50')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Self-healing: переразбор упавших исходящих КП/счетов. Кейс 15.06.2026:
// OpenAI insufficient_quota (429) уронил серию счетов (6190/6191/6192/6195/
// 6198…) — OutboundQuote'ы зависли в status=failed навсегда, счета не попали
// в /dashboard/invoices. Команда повторно дёргает ParseOutboundQuoteJob, когда
// квота восстановилась. Сама пропускает прогон, если circuit-breaker открыт;
// троттлинг/age-window/attempt-cap — в config('services.quotes.reparse_failed').
// См. App\Console\Commands\QuotesReparseFailedCommand.
Schedule::command('quotes:reparse-failed --apply')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Self-healing: подобрать письма, у которых категоризатор упал
// (OpenAI 503/timeout / invalid JSON). Без этого письмо застревает
// без category и не превращается в Request — кейс 25.05.2026 #3681.
// Limit=50 покрывает обычный пик после простоя OpenAI;
// идемпотентно по categorized_at IS NULL.
Schedule::command('mail:categorize --all --limit=50 --include-orphans')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Self-healing: повторная привязка inbound reply'ев, которые в момент
// route() оказались без parent'а (parent застрял в категоризации).
// Кейс 25.05 #3684: пришёл «Re: 901» когда «901» #3681 ещё не имел
// related_request_id из-за упавшей категоризации → linker fallback
// «from_email_open_request» приклеил reply к ЧУЖОЙ открытой заявке.
// Команда берёт категоризованные reply'и без request_id с in_reply_to
// или references и повторяет tryLink — на этот раз parent уже обработан
// предыдущим mail:categorize, level-1 матчит корректно.
Schedule::command('mail:relink-deferred --limit=50')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Self-healing: backfill пропущенных IMAP APPEND в личные ящики менеджеров.
// DeliverToManagerInboxJob иногда теряется (queue:restart в-флайт, worker
// crash, manual processIfRequest пропускающий auto-assign-chain). Каждые
// 30 минут команда находит активные Request без artifact'а inbox_delivery
// и вызывает MailDeliverToManagerService::deliver напрямую. Идемпотентно.
Schedule::command('mail:backfill-manager-deliveries --apply')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Phase 6: автоматические уведомления клиенту (clarification reminder /
// quote followup / invoice expiring soon / invoice expired). Каждый тип
// проверяет is_enabled — по умолчанию все выключены, admin включает через
// UI Admin/Notifications. Cron работает раз в час — обновляет статус
// заявок, ничего не шлёт без явного включения.
Schedule::command('notifications:dispatch-client')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// Авто-закрытие заявок по молчанию клиента (уточнение / КП / счёт). Пороги
// (календарные дни) — в настройках auto_close.*_days, 0 = выкл. Раз в сутки,
// после ежечасных напоминаний (ремайндеры идут раньше закрытия). Закрывает
// через systemCloseLost с причиной no_client_response_to_* / invoice_unpaid;
// восстановление — ручное «↻ Реанимировать». См. RequestsAutoCloseInactiveCommand.
Schedule::command('requests:auto-close-inactive')
    ->dailyAt('08:00')
    ->timezone('Europe/Moscow')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// IQOT (анализ цен конкурентов): новые позиции отправляем ТОЛЬКО в рабочее окно
// 8–18 MSK, каждые 2 часа → 6 заходов/день (8,10,12,14,16,18). За один заход
// уходит порция = daily_limit / runs_per_day (см. IqotDispatchService) — лимит
// не тратится сразу. No-op, если iqot.enabled выключено или ключ не задан.
// ВАЖНО: число заходов должно совпадать с настройкой iqot.runs_per_day (=6).
Schedule::command('iqot:dispatch')
    ->cron('0 8-18/2 * * *')
    ->timezone('Europe/Moscow')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// IQOT: smart-поллинг submissions (только те, у кого X-Next-Check-After прошёл)
// + раскладка готовых отчётов по позициям каталога. Ежечасно.
Schedule::command('iqot:poll')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

// IQOT: ежедневное обновление курсов валют (USD/EUR/CNY) из ЦБ РФ для
// конвертации инвалютных офферов в рубли. ЦБ публикует курс на текущую дату
// накануне вечером — берём утром (07:30 MSK). Уважает тумблер
// iqot.fx_auto_update (выключив, оператор пинит ручные курсы). No-op без сети
// — старые значения сохраняются. См. IqotUpdateFxRatesCommand.
Schedule::command('iqot:update-fx-rates')
    ->dailyAt('07:30')
    ->timezone('Europe/Moscow')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
