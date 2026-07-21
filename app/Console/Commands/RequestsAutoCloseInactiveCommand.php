<?php

namespace App\Console\Commands;

use App\Enums\ClosedLostReason;
use App\Enums\InvoiceStatus;
use App\Enums\MailDirection;
use App\Enums\RequestStatus;
use App\Models\ClarificationBatch;
use App\Models\EmailMessage;
use App\Models\Invoice;
use App\Models\Request;
use App\Models\RequestStateChange;
use App\Services\Calendar\RussianWorkingDayService;
use App\Services\Mail\ClientNotificationService;
use App\Services\Request\RequestStateService;
use App\Services\Settings\SettingsService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Авто-закрытие заявок по молчанию клиента (Foundation §5 «потери по тишине»).
 *
 * Три правила, пороги (РАБОЧИЕ дни, российский произв. календарь) — в настройках
 * админки, 0 = выкл:
 *   - auto_close.clarification_days (4): не ответил на уточнение →
 *     closed_lost `no_client_response_to_clarification`;
 *   - auto_close.quote_days (5): не отреагировал на КП →
 *     closed_lost `no_client_response_to_quote`;
 *   - auto_close.invoice_days (5): счёт не оплачен с даты выставления →
 *     closed_lost `invoice_unpaid`.
 *
 * ГАРД АКТИВНОСТИ: заявка НЕ закрывается, если клиент писал (входящее письмо)
 * в последние N рабочих дней — даже если основной таймер истёк. Это защищает
 * активно ведущиеся сделки (клиент расширил заявку / торгуется) — кейс
 * M-2026-2538 (запросил вторую позицию, общий счёт; не молчание).
 *
 * Закрытие идёт через RequestStateService::systemCloseLost (идемпотентно,
 * аудит `system_close_lost`, attention снимается). Пропускаем терминальные,
 * Paused и PostponedUntil (намеренные паузы). После закрытия — best-effort
 * уведомление клиенту (само ничего не шлёт, пока шаблон OrderClosedLost выключен).
 *
 * Восстановление ошибочно закрытых — ручной кнопкой «↻ Реанимировать» на
 * карточке (менеджер сохраняется).
 *
 * Расписание: routes/console.php — раз в сутки. Запускать руками сперва с
 * --dry-run, при большом объёме исторических кандидатов — партиями через --limit.
 */
class RequestsAutoCloseInactiveCommand extends Command
{
    protected $signature = 'requests:auto-close-inactive
        {--dry-run : Показать кандидатов, ничего не закрывать}
        {--limit=0 : Максимум закрытий за прогон (0 = без лимита)}';

    protected $description = 'Авто-закрытие заявок по молчанию клиента (уточнение / КП / счёт). Пороги — в настройках.';

    /** Статусы, которые НИКОГДА не закрываем авто (намеренные паузы). */
    private const SKIP_STATUSES = [RequestStatus::Paused, RequestStatus::PostponedUntil];

    public function handle(SettingsService $settings, RequestStateService $state, RussianWorkingDayService $cal): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $clarDays = (int) $settings->get('auto_close.clarification_days', 4);
        $quoteDays = (int) $settings->get('auto_close.quote_days', 5);
        // «На согласовании» (UnderReview) — отдельный порог: клиент сказал что
        // рассматривает КП, ему обычно дают больше времени, чем молчащему после
        // отправки КП. Дефолт 0 = выкл (правило вводится позже опциональной
        // настройкой РОПа). Reason тот же — молчание по нашему КП.
        $underReviewDays = (int) $settings->get('auto_close.under_review_days', 0);
        $invoiceDays = (int) $settings->get('auto_close.invoice_days', 5);
        // Пауза после НАШЕГО напоминания клиенту: мы только что попросили
        // ответить — надо дать время. 0 = выкл.
        $graceDays = (int) $settings->get('auto_close.reminder_grace_days', 3);

        if ($dryRun) {
            $this->info('DRY-RUN: ничего не закрывается.');
        }
        $this->info(sprintf(
            'Пороги (раб. дн., 0=выкл): уточнение=%d · КП=%d · согласование=%d · счёт=%d · пауза после напоминания=%d',
            $clarDays, $quoteDays, $underReviewDays, $invoiceDays, $graceDays,
        ));

        $closed = 0;
        $stats = ['clarification' => 0, 'quote' => 0, 'under_review' => 0, 'invoice' => 0];

        $reachedLimit = fn (): bool => $limit > 0 && $closed >= $limit;

        $close = function (Request $req, ClosedLostReason $reason, string $comment, string $bucket)
            use ($dryRun, $state, &$closed, &$stats): void {
            $this->line(sprintf('  %s [%s] → %s · %s', $req->internal_code, $req->status->value, $reason->value, $comment));
            if (! $dryRun) {
                $state->systemCloseLost($req, $reason, $comment);
                $this->notifyClosed($req);
            }
            $closed++;
            $stats[$bucket]++;
        };

        // (a) Молчание после уточняющего вопроса.
        if ($clarDays > 0 && ! $reachedLimit()) {
            // Календарный пред-фильтр (N раб.дн ≥ N кал.дн — безопасный superset),
            // точный раб.-дневной дедлайн считаем в PHP ниже.
            $batches = ClarificationBatch::query()
                ->where('status', ClarificationBatch::STATUS_SENT)
                ->whereNull('answered_at')
                ->where('sent_at', '<', now()->subDays($clarDays))
                ->with('request')
                ->get();
            foreach ($batches as $batch) {
                if ($reachedLimit()) {
                    break;
                }
                $req = $batch->request;
                if (! $this->isClosable($req) || $req->status !== RequestStatus::AwaitingClientClarification) {
                    continue;
                }
                if (! $this->dueBusiness($cal, $batch->sent_at, $clarDays)
                    || $this->clientEngagedRecently($cal, $req, $clarDays)
                    || $this->reminderGraceActive($cal, $req, [\App\Enums\ClientNotificationType::ClarificationReminder->value], $graceDays)) {
                    continue;
                }
                $close($req, ClosedLostReason::NoClientResponseToClarification,
                    sprintf('Клиент не ответил на уточнение более %d раб. дн.', $clarDays), 'clarification');
            }
        }

        // (a2) Уточнение БЕЗ формального батча: статус awaiting_client_clarification
        // в большинстве случаев ставит AI-интент-детектор по обычному письму
        // менеджера (или менеджер вручную) — ClarificationBatch при этом не
        // создаётся, и ветка (a) таких заявок не видит. Кейс M-2026-5102:
        // 322 заявки висели «в уточнении» в среднем 18 дней, автозакрытие
        // находило 0 кандидатов. Якорь тишины — last_activity_at (таймер
        // сбрасывается ответом клиента / действием менеджера, как в ветке b).
        if ($clarDays > 0 && ! $reachedLimit()) {
            $reqs = Request::query()
                ->where('status', RequestStatus::AwaitingClientClarification->value)
                ->whereRaw('COALESCE(last_activity_at, updated_at) < ?', [now()->subDays($clarDays)])
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('clarification_batches')
                        ->whereColumn('clarification_batches.request_id', 'requests.id')
                        ->where('clarification_batches.status', ClarificationBatch::STATUS_SENT)
                        ->whereNull('clarification_batches.answered_at');
                })
                ->get();
            foreach ($reqs as $req) {
                if ($reachedLimit()) {
                    break;
                }
                if (! $this->isClosable($req)) {
                    continue;
                }
                if (! $this->dueBusiness($cal, $req->last_activity_at ?? $req->updated_at, $clarDays)
                    || $this->clientEngagedRecently($cal, $req, $clarDays)
                    || $this->reminderGraceActive($cal, $req, [\App\Enums\ClientNotificationType::ClarificationReminder->value], $graceDays)) {
                    continue;
                }
                $close($req, ClosedLostReason::NoClientResponseToClarification,
                    sprintf('Клиент не ответил на уточнение более %d раб. дн.', $clarDays), 'clarification');
            }
        }

        // (b) Молчание после КП. Anchor — last_activity_at (таймер тишины:
        // сбрасывается при ответе клиента / действии менеджера).
        if ($quoteDays > 0 && ! $reachedLimit()) {
            $reqs = Request::query()
                ->where('status', RequestStatus::Quoted->value)
                ->whereRaw('COALESCE(last_activity_at, updated_at) < ?', [now()->subDays($quoteDays)])
                ->get();
            foreach ($reqs as $req) {
                if ($reachedLimit()) {
                    break;
                }
                if (! $this->isClosable($req)) {
                    continue;
                }
                if (! $this->dueBusiness($cal, $req->last_activity_at ?? $req->updated_at, $quoteDays)
                    || $this->clientEngagedRecently($cal, $req, $quoteDays)
                    || $this->reminderGraceActive($cal, $req, [\App\Enums\ClientNotificationType::QuoteFollowupReminder->value], $graceDays)) {
                    continue;
                }
                $close($req, ClosedLostReason::NoClientResponseToQuote,
                    sprintf('Клиент не отреагировал на КП более %d раб. дн.', $quoteDays), 'quote');
            }
        }

        // (b2) Молчание на согласовании (UnderReview). Клиент подтвердил, что
        // рассматривает КП, но затем замолчал. Та же политика/reason, что и (b),
        // отдельный порог under_review_days (обычно длиннее). Grace-гард — по
        // тому же напоминанию quote_followup_reminder (оно теперь шлётся и в
        // UnderReview). Anchor тишины — last_activity_at.
        if ($underReviewDays > 0 && ! $reachedLimit()) {
            $reqs = Request::query()
                ->where('status', RequestStatus::UnderReview->value)
                ->whereRaw('COALESCE(last_activity_at, updated_at) < ?', [now()->subDays($underReviewDays)])
                ->get();
            foreach ($reqs as $req) {
                if ($reachedLimit()) {
                    break;
                }
                if (! $this->isClosable($req)) {
                    continue;
                }
                if (! $this->dueBusiness($cal, $req->last_activity_at ?? $req->updated_at, $underReviewDays)
                    || $this->clientEngagedRecently($cal, $req, $underReviewDays)
                    || $this->reminderGraceActive($cal, $req, [\App\Enums\ClientNotificationType::QuoteFollowupReminder->value], $graceDays)) {
                    continue;
                }
                $close($req, ClosedLostReason::NoClientResponseToQuote,
                    sprintf('Клиент молчит на согласовании КП более %d раб. дн.', $underReviewDays), 'under_review');
            }
        }

        // (c) Счёт не оплачен N раб. дней с выставления.
        if ($invoiceDays > 0 && ! $reachedLimit()) {
            $invoices = Invoice::query()
                ->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::Expired->value])
                ->whereNull('paid_at')
                ->where('issued_at', '<', now()->subDays($invoiceDays))
                ->with('request')
                ->orderBy('request_id')
                ->get();
            $seen = [];
            foreach ($invoices as $inv) {
                if ($reachedLimit()) {
                    break;
                }
                $req = $inv->request;
                if (! $this->isClosable($req)
                    || ! in_array($req->status, [RequestStatus::Invoiced, RequestStatus::AwaitingInvoice], true)) {
                    continue;
                }
                if (isset($seen[$req->id])) {
                    continue;
                }
                $seen[$req->id] = true;
                // Есть оплаченный счёт по заявке — не закрываем.
                if ($req->invoices()->where('status', InvoiceStatus::Paid->value)->exists()) {
                    continue;
                }
                if (! $this->dueBusiness($cal, $inv->issued_at, $invoiceDays)
                    || $this->clientEngagedRecently($cal, $req, $invoiceDays)
                    || $this->reminderGraceActive($cal, $req, [
                        \App\Enums\ClientNotificationType::InvoiceExpiringSoon->value,
                        \App\Enums\ClientNotificationType::InvoiceExpired->value,
                    ], $graceDays)) {
                    continue;
                }
                $close($req, ClosedLostReason::InvoiceUnpaid,
                    sprintf('Счёт №%s не оплачен более %d раб. дн. с выставления.', $inv->invoice_number, $invoiceDays), 'invoice');
            }
        }

        // (c2) Статус «счёт выставлен», но живых счетов у заявки нет: все
        // аннулированы (типовой случай — разжалованный дубль номера, кейс
        // M-2026-5892: счёт №6613-копия аннулирован, оригинал на другой
        // заявке) либо Invoice вовсе не создан (детектор поднял статус, а
        // создание счёта отсёк гард). Ветка (c) таких не видит — она идёт
        // от pending/expired счетов. Закрываем по той же политике при полной
        // тишине клиента; якорь — last_activity_at.
        if ($invoiceDays > 0 && ! $reachedLimit()) {
            $reqs = Request::query()
                ->where('status', RequestStatus::Invoiced->value)
                ->whereRaw('COALESCE(last_activity_at, updated_at) < ?', [now()->subDays($invoiceDays)])
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('invoices')
                        ->whereColumn('invoices.request_id', 'requests.id')
                        ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value);
                })
                ->get();
            foreach ($reqs as $req) {
                if ($reachedLimit()) {
                    break;
                }
                if (! $this->isClosable($req)) {
                    continue;
                }
                if (! $this->dueBusiness($cal, $req->last_activity_at ?? $req->updated_at, $invoiceDays)
                    || $this->clientEngagedRecently($cal, $req, $invoiceDays)) {
                    continue;
                }
                $close($req, ClosedLostReason::InvoiceUnpaid,
                    sprintf('Живых счетов по заявке нет (аннулированы или не зафиксированы), клиент молчит более %d раб. дн.', $invoiceDays), 'invoice');
            }
        }

        $this->info(sprintf(
            'Закрыто: уточнение=%d · КП=%d · согласование=%d · счёт=%d (всего %d)%s',
            $stats['clarification'], $stats['quote'], $stats['under_review'], $stats['invoice'], $closed,
            $dryRun ? ' [DRY-RUN]' : '',
        ));

        return self::SUCCESS;
    }

    private function isClosable(?Request $req): bool
    {
        if ($req === null || $req->status->isTerminal()) {
            return false;
        }

        return ! in_array($req->status, self::SKIP_STATUSES, true);
    }

    /**
     * Истёк ли раб.-дневной дедлайн: now >= addBusinessDays(anchor, N).
     */
    private function dueBusiness(RussianWorkingDayService $cal, ?CarbonInterface $anchor, int $days): bool
    {
        if ($anchor === null) {
            return false;
        }

        return now()->greaterThanOrEqualTo($cal->addBusinessDays($anchor, $days));
    }

    /**
     * Действует ли пауза после НАШЕГО напоминания клиенту: если ремайндер
     * ушёл недавно, дедлайн тишины продлевается на $graceDays рабочих дней
     * от даты напоминания.
     *
     * Кейс M-2026-7976: ремайндер по КП ушёл в воскресенье 19.07 14:00,
     * авто-закрытие сработало в понедельник 08:00 (порог 5 раб. дн. от КП
     * истёк ровно тогда), а клиент ответил в 10:50 — в первое рабочее утро
     * после нашего же письма, но заявку уже закрыли. Просить ответ и закрывать
     * до того, как клиент успел его дать, нельзя.
     *
     * @param  array<int, string>  $types  типы ремайндеров, релевантные ветке
     */
    private function reminderGraceActive(
        RussianWorkingDayService $cal,
        Request $req,
        array $types,
        int $graceDays,
    ): bool {
        if ($graceDays <= 0 || $types === []) {
            return false;
        }

        $lastReminder = \Illuminate\Support\Facades\DB::table('client_notifications_sent')
            ->where('request_id', $req->id)
            ->whereIn('type', $types)
            ->max('sent_at');

        if ($lastReminder === null) {
            return false;
        }

        return now()->lessThan($cal->addBusinessDays(Carbon::parse($lastReminder), $graceDays));
    }

    /**
     * Писал ли клиент (входящее письмо) в последние N рабочих дней. Если да —
     * заявка ведётся активно, авто-закрывать нельзя (гард M-2026-2538).
     * Нет входящих вовсе → считаем молчанием (нечего «оживлять»).
     */
    private function clientEngagedRecently(RussianWorkingDayService $cal, Request $req, int $days): bool
    {
        $lastInbound = EmailMessage::query()
            ->where('related_request_id', $req->id)
            ->where('direction', MailDirection::Inbound->value)
            ->whereRaw("(detected_artifacts->>'cross_mailbox_copy_of') IS NULL")
            ->max('sent_at');

        if ($lastInbound === null) {
            return false;
        }

        return now()->lessThan($cal->addBusinessDays(Carbon::parse($lastInbound), $days));
    }

    private function notifyClosed(Request $req): void
    {
        try {
            $sc = RequestStateChange::query()
                ->where('request_id', $req->id)
                ->where('event', 'system_close_lost')
                ->latest('id')
                ->first();
            if ($sc !== null) {
                app(ClientNotificationService::class)->sendOrderClosedLost($req->fresh(), $sc);
            }
        } catch (\Throwable $e) {
            Log::warning('auto-close-inactive: client notification failed (non-fatal)', [
                'request_id' => $req->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
