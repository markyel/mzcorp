<?php

namespace App\Services\Analytics;

use App\Enums\ClosedLostReason;
use App\Enums\RequestStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Request;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Аналитика по менеджерам (раздел «Аналитика» + виджеты дашборда).
 *
 * Определения метрик (зафиксированы с заказчиком):
 *  - «обработанная заявка» в динамике = ЗАКРЫТАЯ (won+lost), по дате закрытия;
 *  - «первая реакция» = первый переход статуса менеджером
 *    (request_state_changes.by_user_id IS NOT NULL);
 *  - сводка закрытых (Успех/Потеря) и время закрытия + детальная таблица =
 *    КОГОРТА по дате создания заявки (created_at в периоде);
 *  - атрибуция к менеджеру = requests.assigned_user_id (текущий владелец).
 *
 * Все суточные бакеты — в Europe/Moscow.
 */
class ManagerAnalyticsService
{
    private const TZ = 'Europe/Moscow';

    /** Палитра линий графика (по индексу менеджера). */
    private const PALETTE = [
        '#2563eb', '#dc2626', '#059669', '#d97706', '#7c3aed', '#0891b2',
        '#db2777', '#65a30d', '#ea580c', '#4f46e5', '#0d9488', '#b45309',
    ];

    /**
     * Принцип аналитики: учитываем всё, что ДОШЛО ДО МЕНЕДЖЕРА (попало в его пул,
     * `assigned_user_id IS NOT NULL`), независимо от того, кто/как потом закрыл
     * (менеджер вручную, LLM, cron). Не дошедшее (неназначенные авто-закрытия
     * cron'а, напр. parser_no_content) отсекается самим фильтром по assigned.
     *
     * Единственное доп.исключение — разовая pre-launch заливка / чистка фантомов:
     * старые заявки, закрытые пачкой перед запуском, назначены, но это не
     * активность периода. Отсекаем по событию закрывающего перехода.
     */
    private const NON_WORK_CLOSE_EVENTS = ['bulk_close_historic', 'cleanup.internal_phantom'];

    private function won(): string
    {
        return RequestStatus::ClosedWon->value;
    }

    private function lost(): string
    {
        return RequestStatus::ClosedLost->value;
    }

    /**
     * Отбрасывает заявки, чьё ПОСЛЕДНЕЕ закрывающее (closed_won/closed_lost)
     * событие — разовая pre-launch заливка / чистка фантомов
     * (NON_WORK_CLOSE_EVENTS). Это единственное исключение поверх фильтра по
     * assigned_user_id («дошло до менеджера»); см. NON_WORK_CLOSE_EVENTS.
     *
     * Последний переход определяем через DISTINCT ON (Postgres): на заявку может
     * быть несколько закрытий (реанимация → повторное).
     *
     * @param  \Illuminate\Database\Query\Builder|Builder  $q
     */
    private function excludeNonWorkClose($q): void
    {
        $q->whereNotIn('requests.id', function ($sub) {
            $sub->select('request_id')
                ->fromSub(function ($inner) {
                    $inner->from('request_state_changes')
                        ->whereIn('to_status', [$this->won(), $this->lost()])
                        ->selectRaw('DISTINCT ON (request_id) request_id, event')
                        ->orderBy('request_id')
                        ->orderByDesc('created_at')
                        ->orderByDesc('id');
                }, 'lc')
                ->whereIn('event', self::NON_WORK_CLOSE_EVENTS);
        });
    }

    /**
     * Менеджеры-обработчики заявок (Manager + РОП), активные, по имени.
     *
     * @return Collection<int, User>
     */
    public function managers(): Collection
    {
        return User::query()
            ->active()
            ->role(RoleEnum::requestHandlerRoles())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Цвет линии для менеджера по его позиции в отсортированном списке.
     */
    public function colorFor(int $index): string
    {
        return self::PALETTE[$index % count(self::PALETTE)];
    }

    /**
     * Динамика закрытых заявок по дням и по менеджерам (won+lost),
     * по дате закрытия. Все менеджеры — на одном графике.
     *
     * @param  array<int, int>  $managerIds  фильтр (пусто = все)
     * @return array{
     *     dates: list<string>,
     *     labels: list<string>,
     *     series: array<int, array{id:int, name:string, color:string, points:list<int>, won:int, lost:int, total:int}>,
     *     max: int
     * }
     */
    public function closedDynamics(CarbonImmutable $from, CarbonImmutable $to, array $managerIds = []): array
    {
        $managers = $this->managers();
        if ($managerIds !== []) {
            $managers = $managers->whereIn('id', $managerIds)->values();
        }
        if ($managers->isEmpty()) {
            return ['dates' => [], 'labels' => [], 'series' => [], 'max' => 0];
        }

        $q = DB::table('requests')
            ->whereIn('status', [$this->won(), $this->lost()])
            ->whereNotNull('closed_at')
            ->whereNotNull('assigned_user_id')
            ->whereIn('assigned_user_id', $managers->pluck('id'))
            ->whereBetween('closed_at', [$from->utc(), $to->utc()]);
        $this->excludeNonWorkClose($q);

        $rows = $q->selectRaw("
                DATE(closed_at AT TIME ZONE '" . self::TZ . "') AS day,
                assigned_user_id,
                status,
                COUNT(*) AS c
            ")
            ->groupBy('day', 'assigned_user_id', 'status')
            ->get();

        // byUser[userId][day] = ['won'=>x,'lost'=>y]
        $byUser = [];
        foreach ($rows as $r) {
            $uid = (int) $r->assigned_user_id;
            $day = (string) $r->day;
            $byUser[$uid][$day] ??= ['won' => 0, 'lost' => 0];
            if ($r->status === $this->won()) {
                $byUser[$uid][$day]['won'] += (int) $r->c;
            } else {
                $byUser[$uid][$day]['lost'] += (int) $r->c;
            }
        }

        // Ось дат (включительно, MSK).
        $fromMsk = $from->setTimezone(self::TZ)->startOfDay();
        $toMsk = $to->setTimezone(self::TZ)->startOfDay();
        $dayCount = (int) min(366, max(1, $fromMsk->diffInDays($toMsk) + 1));
        $dates = [];
        $labels = [];
        for ($i = 0; $i < $dayCount; $i++) {
            $d = $fromMsk->addDays($i);
            $dates[] = $d->format('Y-m-d');
            $labels[] = $d->format('d.m');
        }

        $series = [];
        $max = 0;
        $idx = 0;
        foreach ($managers as $m) {
            $points = [];
            $pointsWon = [];
            $pointsLost = [];
            $wonTot = 0;
            $lostTot = 0;
            foreach ($dates as $day) {
                $cell = $byUser[$m->id][$day] ?? ['won' => 0, 'lost' => 0];
                $v = $cell['won'] + $cell['lost'];
                $points[] = $v;
                $pointsWon[] = $cell['won'];
                $pointsLost[] = $cell['lost'];
                $wonTot += $cell['won'];
                $lostTot += $cell['lost'];
                $max = max($max, $v);
            }
            $series[] = [
                'id' => $m->id,
                'name' => $m->name,
                'color' => $this->colorFor($idx),
                // points — won+lost (виджет-мультилиния дашборда);
                // points_won / points_lost — для diverging stacked area в «Аналитике».
                'points' => $points,
                'points_won' => $pointsWon,
                'points_lost' => $pointsLost,
                'won' => $wonTot,
                'lost' => $lostTot,
                'total' => $wonTot + $lostTot,
            ];
            $idx++;
        }

        return ['dates' => $dates, 'labels' => $labels, 'series' => $series, 'max' => $max];
    }

    /**
     * Сводка закрытых по менеджерам — когорта по дате СОЗДАНИЯ заявки.
     * Возвращает won / lost / open (ещё не закрыто) / total + win_rate.
     *
     * @param  array<int, int>  $managerIds
     * @return list<array{id:int, name:string, won:int, lost:int, open:int, total:int, win_rate:?float}>
     */
    public function wonLostByManager(CarbonImmutable $from, CarbonImmutable $to, array $managerIds = []): array
    {
        $managers = $this->managers();
        if ($managerIds !== []) {
            $managers = $managers->whereIn('id', $managerIds)->values();
        }
        if ($managers->isEmpty()) {
            return [];
        }

        $q = DB::table('requests')
            ->whereNotNull('assigned_user_id')
            ->whereIn('assigned_user_id', $managers->pluck('id'))
            ->whereBetween('created_at', [$from->utc(), $to->utc()]);
        $this->excludeNonWorkClose($q);

        $rows = $q->selectRaw("
                assigned_user_id,
                COUNT(*) FILTER (WHERE status = ?) AS won,
                COUNT(*) FILTER (WHERE status = ?) AS lost,
                COUNT(*) FILTER (WHERE status NOT IN (?, ?)) AS open,
                COUNT(*) AS total
            ", [$this->won(), $this->lost(), $this->won(), $this->lost()])
            ->groupBy('assigned_user_id')
            ->get()
            ->keyBy('assigned_user_id');

        $out = [];
        foreach ($managers as $m) {
            $row = $rows->get($m->id);
            $won = (int) ($row->won ?? 0);
            $lost = (int) ($row->lost ?? 0);
            $closed = $won + $lost;
            $out[] = [
                'id' => $m->id,
                'name' => $m->name,
                'won' => $won,
                'lost' => $lost,
                'open' => (int) ($row->open ?? 0),
                'total' => (int) ($row->total ?? 0),
                'win_rate' => $closed > 0 ? round($won * 100 / $closed, 1) : null,
            ];
        }

        // Сортировка по числу ЗАКРЫТЫХ (won+lost) — раздел про закрытые заявки.
        usort($out, fn ($a, $b) => ($b['won'] + $b['lost']) <=> ($a['won'] + $a['lost']));

        return $out;
    }

    /**
     * Время закрытия (created_at → closed_at) по менеджерам, split Успех/Потеря.
     * Когорта по дате создания, только закрытые. Avg + медиана в часах.
     *
     * @param  array<int, int>  $managerIds
     * @return list<array{id:int, name:string,
     *     won_count:int, won_avg_h:?float, won_median_h:?float,
     *     lost_count:int, lost_avg_h:?float, lost_median_h:?float}>
     */
    public function timeToCloseByManager(CarbonImmutable $from, CarbonImmutable $to, array $managerIds = []): array
    {
        $managers = $this->managers();
        if ($managerIds !== []) {
            $managers = $managers->whereIn('id', $managerIds)->values();
        }
        if ($managers->isEmpty()) {
            return [];
        }

        $q = DB::table('requests')
            ->whereNotNull('assigned_user_id')
            ->whereIn('assigned_user_id', $managers->pluck('id'))
            ->whereIn('status', [$this->won(), $this->lost()])
            ->whereNotNull('closed_at')
            ->whereBetween('created_at', [$from->utc(), $to->utc()]);
        $this->excludeNonWorkClose($q);

        $rows = $q->selectRaw("
                assigned_user_id,
                status,
                COUNT(*) AS c,
                AVG(EXTRACT(EPOCH FROM (closed_at - created_at))) AS avg_sec,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (closed_at - created_at))) AS median_sec
            ")
            ->groupBy('assigned_user_id', 'status')
            ->get();

        // byUser[uid][status] = {c, avg_sec, median_sec}
        $byUser = [];
        foreach ($rows as $r) {
            $byUser[(int) $r->assigned_user_id][(string) $r->status] = $r;
        }

        $toHours = fn ($sec) => $sec === null ? null : round(((float) $sec) / 3600, 1);

        $out = [];
        foreach ($managers as $m) {
            $w = $byUser[$m->id][$this->won()] ?? null;
            $l = $byUser[$m->id][$this->lost()] ?? null;
            $out[] = [
                'id' => $m->id,
                'name' => $m->name,
                'won_count' => (int) ($w->c ?? 0),
                'won_avg_h' => $toHours($w->avg_sec ?? null),
                'won_median_h' => $toHours($w->median_sec ?? null),
                'lost_count' => (int) ($l->c ?? 0),
                'lost_avg_h' => $toHours($l->avg_sec ?? null),
                'lost_median_h' => $toHours($l->median_sec ?? null),
            ];
        }

        usort($out, fn ($a, $b) => ($b['won_count'] + $b['lost_count']) <=> ($a['won_count'] + $a['lost_count']));

        return $out;
    }

    /**
     * Разбивка причин Потери (closed_lost_reason) для заявок, ЗАКРЫТЫХ в
     * периоде (по дате закрытия — причина проставляется в момент закрытия).
     * Атрибуция к менеджеру — assigned_user_id; пустой фильтр = все.
     *
     * Базис «по дате закрытия» (а не когорта по дате создания, как в блоках
     * Успех/Потеря): отвечает на вопрос «по каким причинам мы закрывали в
     * Потерю за период».
     *
     * «Нерабочие» закрытия (авто + историческая заливка, см. excludeNonWorkClose)
     * в total/rows НЕ попадают — их суммарное число отдаётся отдельным полем
     * `excluded` для справки.
     *
     * @param  array<int, int>  $managerIds
     * @return array{
     *     total:int,
     *     excluded:int,
     *     rows:list<array{reason:?string, label:string, count:int, share:float}>
     * }
     */
    public function lostReasonsBreakdown(CarbonImmutable $from, CarbonImmutable $to, array $managerIds = []): array
    {
        $base = DB::table('requests')
            ->where('status', $this->lost())
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$from->utc(), $to->utc()]);

        if ($managerIds !== []) {
            $base->whereIn('assigned_user_id', $managerIds);
        }

        // Все потери периода (включая не дошедшие до менеджера) — для расчёта
        // числа «не учтено» (excluded).
        $totalLost = (int) (clone $base)->count();

        // Учитываем только дошедшее до менеджера (assigned) и не разовую заливку.
        $kept = (clone $base)->whereNotNull('assigned_user_id');
        $this->excludeNonWorkClose($kept);

        $rows = $kept
            ->selectRaw('closed_lost_reason, COUNT(*) AS c')
            ->groupBy('closed_lost_reason')
            ->orderByDesc('c')
            ->get();

        $total = 0;
        foreach ($rows as $r) {
            $total += (int) $r->c;
        }
        $excluded = $totalLost - $total;

        $out = [];
        foreach ($rows as $r) {
            $reason = $r->closed_lost_reason !== null ? (string) $r->closed_lost_reason : null;
            $label = $reason !== null
                ? (ClosedLostReason::tryFrom($reason)?->label() ?? $reason)
                : 'Причина не указана';
            $count = (int) $r->c;
            $out[] = [
                'reason' => $reason,
                'label' => $label,
                'count' => $count,
                'share' => $total > 0 ? round($count * 100 / $total, 1) : 0.0,
            ];
        }

        return ['total' => $total, 'excluded' => $excluded, 'rows' => $out];
    }

    /**
     * Builder для детальной таблицы заявок (когорта по дате создания),
     * с под-запросами: первая реакция, отправка КП, число доп.вопросов.
     * Компонент сам пагинирует.
     *
     * @param  array<int, int>  $managerIds
     */
    public function requestDetailsQuery(CarbonImmutable $from, CarbonImmutable $to, array $managerIds = []): Builder
    {
        $firstReaction = "(SELECT MIN(rsc.created_at) FROM request_state_changes rsc
                           WHERE rsc.request_id = requests.id AND rsc.by_user_id IS NOT NULL)";
        $quoteSent = "(SELECT MIN(q.sent_at) FROM quotations q
                       WHERE q.request_id = requests.id AND q.status = '" . \App\Enums\QuotationStatus::Sent->value . "')";
        $clarCount = "(SELECT COUNT(*) FROM clarification_questions cq
                       JOIN clarification_batches cb ON cb.id = cq.batch_id
                       WHERE cb.request_id = requests.id AND cb.status IN ('sent', 'answered'))";

        $q = Request::query()
            ->whereBetween('created_at', [$from->utc(), $to->utc()])
            ->whereNotNull('assigned_user_id')
            // Только закрытые (won/lost) — раздел про обработку закрытых заявок.
            ->whereIn('status', [$this->won(), $this->lost()])
            ->with('assignedUser:id,name')
            ->select('requests.*')
            ->selectRaw("$firstReaction AS first_reaction_at")
            ->selectRaw("$quoteSent AS quote_sent_at")
            ->selectRaw("$clarCount AS clarifications_count")
            ->orderByDesc('created_at');

        $this->excludeNonWorkClose($q);

        if ($managerIds !== []) {
            $q->whereIn('assigned_user_id', $managerIds);
        }

        return $q;
    }

    /**
     * Топ позиций каталога по продажам/потерям (для отчёта «самые продаваемые /
     * самые отказные»). На основе закрытых заявок (по дате закрытия):
     *  - won_units / won_deals = сколько единиц продано (closed_won);
     *  - lost_units / lost_deals = сколько единиц проиграно (closed_lost).
     * Единица — `parsed_qty` позиции (в её ед.). Учитываются только catalog-matched
     * активные позиции. Потери очищены через excludeNonWorkClose (победы не
     * затрагиваются — нерабочие закрытия бывают только у потерь). Возвращает
     * Query\Builder для пагинации в компоненте; сортировка по won_units/lost_units.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function topPositionsQuery(CarbonImmutable $from, CarbonImmutable $to, string $sort = 'won', string $search = '')
    {
        $won = $this->won();
        $lost = $this->lost();

        $q = DB::table('request_items')
            ->join('requests', 'requests.id', '=', 'request_items.request_id')
            ->join('catalog_items', 'catalog_items.id', '=', 'request_items.catalog_item_id')
            ->whereIn('requests.status', [$won, $lost])
            ->whereNotNull('request_items.catalog_item_id')
            ->where('request_items.is_active', true)
            ->whereNotNull('requests.closed_at')
            ->whereBetween('requests.closed_at', [$from->utc(), $to->utc()]);

        // Победы не трогает; у потерь отсекает нерабочие закрытия (заливка/авто-триаж).
        $this->excludeNonWorkClose($q);

        if ($search !== '') {
            $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $q->where(function ($w) use ($term) {
                $w->where('catalog_items.name', 'ilike', $term)
                    ->orWhere('catalog_items.sku', 'ilike', $term)
                    ->orWhere('catalog_items.brand_article', 'ilike', $term);
            });
        }

        $q->groupBy('request_items.catalog_item_id', 'catalog_items.sku', 'catalog_items.name')
            ->selectRaw(
                'request_items.catalog_item_id, catalog_items.sku, catalog_items.name,
                 COALESCE(SUM(request_items.parsed_qty) FILTER (WHERE requests.status = ?), 0) AS won_units,
                 COUNT(DISTINCT requests.id) FILTER (WHERE requests.status = ?) AS won_deals,
                 COALESCE(SUM(request_items.parsed_qty) FILTER (WHERE requests.status = ?), 0) AS lost_units,
                 COUNT(DISTINCT requests.id) FILTER (WHERE requests.status = ?) AS lost_deals',
                [$won, $won, $lost, $lost]
            );

        if ($sort === 'lost') {
            $q->orderByDesc('lost_units')->orderByDesc('lost_deals');
        } else {
            $q->orderByDesc('won_units')->orderByDesc('won_deals');
        }

        return $q;
    }
}
