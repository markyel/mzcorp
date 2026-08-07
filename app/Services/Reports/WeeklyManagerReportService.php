<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Models\WeeklyReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Расчёт и генерация еженедельных персональных отчётов менеджеров.
 *
 * Метрики «всего» берутся по детектору исходящей почты (outbound_quotes для
 * КП/счетов, письма из ящика менеджера) — включая документы, отправленные вне
 * системы; «через систему» — то, что сгенерировано/отправлено в CRM.
 * Просрочки/повисшие/зависшие — снимок на момент генерации (замораживается в
 * weekly_reports.data). См. reports:weekly-generate.
 */
class WeeklyManagerReportService
{
    private const PRE_QUOTE = ['new', 'assigned', 'in_progress', 'awaiting_client_clarification'];

    private const OUR_DEBT = ['new', 'assigned', 'in_progress', 'awaiting_invoice'];

    private const MONTHS = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
        'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    /** Сгенерировать/обновить отчёты всех менеджеров за неделю [from..to]. */
    public function generateForWeek(Carbon $from, Carbon $to): int
    {
        $managers = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['manager', 'head_of_sales']))->get();
        $n = 0;
        foreach ($managers as $m) {
            $data = $this->computeData($m, $from, $to);
            WeeklyReport::updateOrCreate(
                ['user_id' => $m->id, 'period_start' => $from->toDateString()],
                ['period_end' => $to->toDateString(), 'data' => $data],
            );
            $n++;
        }

        return $n;
    }

    /**
     * Полный снимок метрик менеджера за период.
     *
     * @return array<string, mixed>
     */
    public function computeData(User $m, Carbon $from, Carbon $to): array
    {
        $id = $m->id;
        $mbx = DB::table('mailboxes')->where('owner_user_id', $id)->pluck('id')->all();

        return [
            'manager' => ['id' => $id, 'name' => $m->name],
            'period' => [
                'start' => $from->toDateString(),
                'end' => $to->toDateString(),
                'label' => $this->periodLabel($from, $to),
                'week' => (int) $from->isoWeek(),
            ],
            'activity' => $this->activity($id, $mbx, $from, $to),
            'result' => $this->result($id, $from, $to),
            'lost' => $this->lost($id, $from, $to),
            'attention' => [
                'overdue' => $this->overdue($id),
                'waiting' => $this->waiting($id),
            ],
            'warm' => [
                'price_ready' => $this->priceReadyNoQuote($id),
                'awaiting_invoice' => $this->awaitingInvoice($id),
            ],
            'stuck_rfq' => $this->stuckRfq($id),
        ];
    }

    /** @return array<string, int|float> */
    private function activity(int $id, array $mbx, Carbon $from, Carbon $to): array
    {
        $assigned = DB::table('request_assignments')->where('user_id', $id)
            ->whereBetween('assigned_at', [$from, $to])->distinct('request_id')->count('request_id');

        $emailsTotal = empty($mbx) ? 0 : DB::table('email_messages')->whereIn('mailbox_id', $mbx)
            ->where('direction', 'outbound')
            ->where(fn ($q) => $q->whereNull('is_draft')->orWhere('is_draft', false))
            ->whereBetween('sent_at', [$from, $to])->count();
        $emailsSystem = DB::table('email_messages')->where('draft_author_user_id', $id)
            ->where('direction', 'outbound')->where('is_draft', false)
            ->whereBetween('sent_at', [$from, $to])->count();

        $rfqs = DB::table('supplier_inquiries')->where('created_by_user_id', $id)
            ->whereBetween('created_at', [$from, $to])->count();
        $offers = DB::table('supplier_offers as o')->join('supplier_inquiries as si', 'si.id', '=', 'o.supplier_inquiry_id')
            ->where('si.created_by_user_id', $id)->where('o.outcome', 'quoted')
            ->whereBetween('o.created_at', [$from, $to])->count();

        $kp = $this->outboundDeduped('outbound_quotation_full', $id, $from, $to);
        $inv = $this->outboundDeduped('outbound_invoice', $id, $from, $to);
        $kpSystem = DB::table('quotations')->where('responsible_user_id', $id)
            ->whereNotNull('sent_at')->whereBetween('sent_at', [$from, $to])->count();

        $paidSum = (float) DB::table('invoices as i')->join('requests as r', 'r.id', '=', 'i.request_id')
            ->where('r.assigned_user_id', $id)->whereNotNull('i.paid_at')->whereBetween('i.paid_at', [$from, $to])
            ->sum(DB::raw('COALESCE(i.paid_amount, i.amount_snapshot)'));

        return [
            'assigned' => $assigned,
            'emails_total' => $emailsTotal,
            'emails_system' => $emailsSystem,
            'rfqs' => $rfqs,
            'offers' => $offers,
            'kp_count' => (int) $kp->c,
            'kp_sum' => (float) $kp->s,
            'kp_system' => $kpSystem,
            'inv_count' => (int) $inv->c,
            'inv_sum' => (float) $inv->s,
            'paid_sum' => $paidSum,
        ];
    }

    /** Дедуп КП/счетов по номеру (детектор исходящей), атрибуция по заявке. */
    private function outboundDeduped(string $type, int $id, Carbon $from, Carbon $to): object
    {
        return DB::selectOne("
            WITH d AS (
                SELECT DISTINCT ON (COALESCE(oq.document_number, oq.id::text)) oq.total_amount
                FROM outbound_quotes oq JOIN requests r ON r.id = oq.request_id
                WHERE oq.document_type = ? AND r.assigned_user_id = ?
                  AND oq.document_date >= ? AND oq.document_date <= ?
                ORDER BY COALESCE(oq.document_number, oq.id::text), oq.id DESC)
            SELECT count(*) c, COALESCE(sum(total_amount), 0) s FROM d
        ", [$type, $id, $from, $to]);
    }

    /** @return array<string, int|float> */
    private function result(int $id, Carbon $from, Carbon $to): array
    {
        $won = DB::table('requests')->where('requests.assigned_user_id', $id)->where('requests.status', 'closed_won')
            ->whereBetween('requests.updated_at', [$from, $to]);
        $count = (clone $won)->count();
        $sum = (float) (clone $won)->leftJoin('invoices as i', 'i.request_id', '=', 'requests.id')
            ->sum('i.amount_snapshot');
        $paid = (float) DB::table('invoices as i')->join('requests as r', 'r.id', '=', 'i.request_id')
            ->where('r.assigned_user_id', $id)->whereNotNull('i.paid_at')->whereBetween('i.paid_at', [$from, $to])
            ->sum(DB::raw('COALESCE(i.paid_amount, i.amount_snapshot)'));

        return ['won' => $count, 'won_sum' => $sum, 'paid_sum' => $paid];
    }

    /** Потеряно: по этапу/причине, с суммами для «после КП» и «после счёта». */
    private function lost(int $id, Carbon $from, Carbon $to): array
    {
        $base = fn () => DB::table('requests')->where('assigned_user_id', $id)
            ->where('status', 'closed_lost')->whereBetween('updated_at', [$from, $to]);

        $byReason = [];
        foreach ($base()->select('closed_lost_reason', DB::raw('count(*) c'))->groupBy('closed_lost_reason')->get() as $r) {
            $byReason[$r->closed_lost_reason ?? '_'] = (int) $r->c;
        }
        $r = fn (string $k) => $byReason[$k] ?? 0;

        $total = array_sum($byReason);
        $noise = $r('off_topic') + $r('spam') + $r('duplicate');

        $kpSum = $this->lostSum($id, $from, $to, 'no_client_response_to_quote', 'outbound_quotation_full');
        $invSum = $this->lostSum($id, $from, $to, 'invoice_unpaid', 'outbound_invoice');

        $stages = [];
        $add = function (string $label, int $count, ?float $sum, string $tone) use (&$stages) {
            if ($count > 0) {
                $stages[] = ['label' => $label, 'count' => $count, 'sum' => $sum, 'tone' => $tone];
            }
        };
        $add('После КП — клиент не ответил', $r('no_client_response_to_quote'), $kpSum, 'amber');
        $add('После счёта — не оплачено', $r('invoice_unpaid'), $invSum, 'red');
        $add('На уточнении — клиент не ответил', $r('no_client_response_to_clarification'), null, 'amber');
        $add('Не смогли предложить', $r('we_cant_offer'), null, 'neutral');
        $add('Отказ по цене / иное', $r('client_declined_price') + $r('client_declined_other'), null, 'neutral');
        $add('Прочее — закрыто вручную', $r('manual_other'), null, 'neutral');

        return [
            'total' => $total,
            'real' => $total - $noise,
            'noise' => $noise,
            'stages' => $stages,
        ];
    }

    private function lostSum(int $id, Carbon $from, Carbon $to, string $reason, string $docType): float
    {
        $row = DB::selectOne("
            SELECT COALESCE(sum(t.amt), 0) s FROM (
                SELECT DISTINCT ON (r.id) oq.total_amount amt
                FROM requests r JOIN outbound_quotes oq ON oq.request_id = r.id AND oq.document_type = ?
                WHERE r.assigned_user_id = ? AND r.status = 'closed_lost'
                  AND r.updated_at BETWEEN ? AND ? AND r.closed_lost_reason = ?
                ORDER BY r.id, oq.id DESC) t
        ", [$docType, $id, $from, $to, $reason]);

        return (float) $row->s;
    }

    /** Просрочено — наш долг, без движения >3 дней (снимок). @return list<array> */
    private function overdue(int $id): array
    {
        return DB::table('requests')->where('assigned_user_id', $id)
            ->whereIn('status', self::OUR_DEBT)->where('updated_at', '<', now()->subDays(3))
            ->orderBy('updated_at')->limit(15)
            ->get(['id', 'internal_code', 'status', 'updated_at'])
            ->map(fn ($r) => [
                'id' => $r->id, 'code' => $r->internal_code,
                'status' => $this->statusLabel($r->status),
                'meta' => 'тишина с '.Carbon::parse($r->updated_at)->format('d.m'),
            ])->all();
    }

    /** Клиент написал последним — ждёт ответа (снимок). @return list<array> */
    private function waiting(int $id): array
    {
        $rows = DB::select("
            SELECT r.id, r.internal_code, r.status, max(em.sent_at) last_in
            FROM requests r JOIN email_messages em ON em.related_request_id = r.id
            WHERE r.assigned_user_id = ? AND r.status IN ('new','assigned','in_progress','awaiting_client_clarification')
              AND em.id = (SELECT e2.id FROM email_messages e2 WHERE e2.related_request_id = r.id
                           ORDER BY e2.sent_at DESC NULLS LAST, e2.id DESC LIMIT 1)
              AND em.direction = 'inbound'
            GROUP BY r.id, r.internal_code, r.status ORDER BY 4 LIMIT 15
        ", [$id]);

        return array_map(function ($r) {
            $since = Carbon::parse($r->last_in);
            $days = (int) $since->diffInDays(now());
            $meta = $days >= 7 ? "ждёт {$days} дн. · с ".$since->format('d.m') : 'ждёт с '.$since->format('d.m');

            return ['id' => $r->id, 'code' => $r->internal_code,
                'status' => $this->statusLabel($r->status), 'meta' => $meta, 'days' => $days];
        }, $rows);
    }

    /** Появилась актуальная цена, но КП не отправлен. @return list<array> */
    private function priceReadyNoQuote(int $id): array
    {
        $rows = DB::select("
            SELECT r.id, r.internal_code, r.updated_at FROM requests r
            WHERE r.assigned_user_id = ? AND r.status IN ('new','assigned','in_progress','awaiting_client_clarification')
              AND EXISTS (SELECT 1 FROM request_items ri WHERE ri.request_id = r.id AND ri.is_active AND ri.catalog_item_id IS NOT NULL)
              AND NOT EXISTS (SELECT 1 FROM request_items ri JOIN catalog_items ci ON ci.id = ri.catalog_item_id
                    WHERE ri.request_id = r.id AND ri.is_active AND ci.is_price_actual = false)
              AND NOT EXISTS (SELECT 1 FROM outbound_quotes oq WHERE oq.request_id = r.id AND oq.document_type = 'outbound_quotation_full')
            ORDER BY r.updated_at LIMIT 20
        ", [$id]);

        return array_map(fn ($r) => [
            'id' => $r->id, 'code' => $r->internal_code, 'status' => 'готова к КП',
            'meta' => 'с '.Carbon::parse($r->updated_at)->format('d.m'),
        ], $rows);
    }

    /** Клиент ждёт счёт после КП (awaiting_invoice). @return list<array> */
    private function awaitingInvoice(int $id): array
    {
        return DB::table('requests')->where('assigned_user_id', $id)->where('status', 'awaiting_invoice')
            ->orderBy('updated_at')->limit(20)->get(['id', 'internal_code', 'updated_at'])
            ->map(fn ($r) => [
                'id' => $r->id, 'code' => $r->internal_code, 'status' => 'ждёт счёт',
                'meta' => 'с '.Carbon::parse($r->updated_at)->format('d.m'),
            ])->all();
    }

    /** Зависшие запросы поставщикам менеджера (снимок). */
    private function stuckRfq(int $id): array
    {
        $max = (int) config('services.suppliers.reminder.max', 2);
        $rows = DB::select("
            SELECT si.supplier_name, si.supplier_email, si.reminders_sent,
                   (SELECT count(*) FROM email_messages e WHERE e.supplier_inquiry_id = si.id AND e.direction='inbound') inb,
                   (SELECT count(*) FROM supplier_offers o WHERE o.supplier_inquiry_id = si.id) off
            FROM supplier_inquiries si
            WHERE si.created_by_user_id = ? AND si.status = 'open'
              AND EXISTS (SELECT 1 FROM supplier_inquiry_items it WHERE it.supplier_inquiry_id = si.id)
        ", [$id]);

        $count = 0;
        $examples = [];
        foreach ($rows as $s) {
            $stuck = $s->inb == 0 ? ($s->reminders_sent >= $max) : ($s->off == 0);
            if (! $stuck) {
                continue;
            }
            $count++;
            if (count($examples) < 5) {
                $examples[] = [
                    'name' => $s->supplier_name ?: $s->supplier_email,
                    'state' => $s->inb > 0 ? 'ответил без оффера' : 'тишина, напом. '.$s->reminders_sent,
                    'answered' => $s->inb > 0,
                ];
            }
        }

        return ['count' => $count, 'examples' => $examples];
    }

    private function periodLabel(Carbon $from, Carbon $to): string
    {
        $m1 = self::MONTHS[(int) $from->month];
        $m2 = self::MONTHS[(int) $to->month];

        return $from->day.' '.$m1.' — '.$to->day.' '.$m2.' '.$to->year;
    }

    private function statusLabel(string $status): string
    {
        try {
            return \App\Enums\RequestStatus::from($status)->label();
        } catch (\Throwable) {
            return $status;
        }
    }
}
