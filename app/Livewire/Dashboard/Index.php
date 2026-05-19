<?php

namespace App\Livewire\Dashboard;

use App\Enums\AiDecisionStatus;
use App\Enums\DetectorType;
use App\Enums\EmailCategory;
use App\Enums\RequestStatus;
use App\Enums\Role as RoleEnum;
use App\Models\AiDecision;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Request;
use App\Models\RequestStateChange;
use App\Models\RoutedMail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Главный дашборд (Phase 1.11 v0).
 *
 * Foundation §«Декомпозиция Фазы 1»:
 *   «Дашборд РОПа v0: health-check ящиков, счётчики писем по типам, метрики AI»
 *
 * Для менеджера — урезанная версия (свои метрики + общий health).
 * Для РОП/director/secretary — полный dashboard.
 */
class Index extends Component
{
    /**
     * Период (в днях) для funnel, conversion, heatmap, sparklines.
     * Переключается чипами 7/30/90 в шапке dashboard.
     */
    #[Url(as: 'period', except: 30)]
    public int $periodDays = 30;

    public function setPeriod(int $days): void
    {
        if (in_array($days, [7, 30, 90], true)) {
            $this->periodDays = $days;
        }
    }

    /**
     * Начало периода в moscow-tz (timestamps пишутся в UTC, но мы
     * группируем по локальному календарю РОПа для heatmap/funnel).
     */
    private function periodStart(): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays($this->periodDays);
    }

    #[Computed]
    public function isPrivileged(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([
            RoleEnum::HeadOfSales->value,
            RoleEnum::Director->value,
            RoleEnum::Secretary->value,
        ]);
    }

    #[Computed]
    public function requestCounts(): array
    {
        $userId = auth()->id();
        $base = Request::query();
        if (! $this->isPrivileged) {
            $base->where('assigned_user_id', $userId);
        }

        $total = (clone $base)->count();
        $new = (clone $base)->where('status', RequestStatus::New->value)->count();
        $assigned = (clone $base)->where('status', RequestStatus::Assigned->value)->count();
        $unassigned = (clone $base)->whereNull('assigned_user_id')->count();

        $today = (clone $base)->where('created_at', '>=', now()->subDay())->count();
        $week = (clone $base)->where('created_at', '>=', now()->subWeek())->count();

        // Phase 1.11 (Foundation §5.3): KPI «Просрочено» и «Сегодня дедлайн».
        // Считаются только в active-области (исключаем silent-статусы),
        // даже у менеджера — это его рабочее напоминание.
        $silent = [
            RequestStatus::Paused->value,
            RequestStatus::ClosedWon->value,
            RequestStatus::ClosedLost->value,
            RequestStatus::Pending->value,
            RequestStatus::Paid->value,
        ];
        $overdue = (clone $base)
            ->where('attention_level', 1)
            ->whereNotIn('status', $silent)
            ->count();
        $dueToday = (clone $base)
            ->whereNotNull('attention_required_at')
            ->whereBetween('attention_required_at', [now(), now()->endOfDay()])
            ->where('attention_level', 0)
            ->whereNotIn('status', $silent)
            ->count();

        return compact('total', 'new', 'assigned', 'unassigned', 'today', 'week', 'overdue', 'dueToday');
    }

    /**
     * Распределение писем по EmailCategory за последние 30 дней.
     *
     * @return array<int, array{class: string, label: string, count: int}>
     */
    #[Computed]
    public function categoryBreakdown(): array
    {
        $rows = EmailMessage::query()
            ->where('direction', 'inbound')
            ->whereNotNull('category')
            ->where('categorized_at', '>=', now()->subDays(30))
            ->groupBy('category')
            ->selectRaw('category AS class, COUNT(*) AS c')
            ->orderByDesc('c')
            ->get();

        return $rows->map(function ($r) {
            $enum = EmailCategory::tryFrom($r->class);

            return [
                'class' => $r->class,
                'label' => $enum?->label() ?? $r->class,
                'count' => (int) $r->c,
            ];
        })->all();
    }

    /**
     * Какой % писем за 30 дней успешно категоризирован.
     */
    #[Computed]
    public function categoryCoverage(): array
    {
        $total = EmailMessage::where('direction', 'inbound')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
        $classified = EmailMessage::where('direction', 'inbound')
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('categorized_at')
            ->count();

        return [
            'total' => $total,
            'classified' => $classified,
            'percent' => $total > 0 ? round($classified * 100 / $total) : 0,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Mailbox>
     */
    #[Computed]
    public function mailboxes()
    {
        return Mailbox::orderBy('id')->get();
    }

    /**
     * Топ-5 менеджеров по числу активных заявок.
     *
     * @return array<int, array{name: string, email: string, total: int, new: int}>
     */
    #[Computed]
    public function managersLoad(): array
    {
        if (! $this->isPrivileged) {
            return [];
        }

        $managers = User::role(RoleEnum::requestHandlerRoles())->get();
        if ($managers->isEmpty()) {
            return [];
        }

        $loads = Request::query()
            ->whereIn('assigned_user_id', $managers->pluck('id'))
            ->groupBy('assigned_user_id')
            ->selectRaw("
                assigned_user_id,
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = '" . RequestStatus::New->value . "') AS new_count
            ")
            ->get()
            ->keyBy('assigned_user_id');

        return $managers->map(function (User $u) use ($loads) {
            $row = $loads->get($u->id);

            return [
                'name' => $u->name,
                'email' => $u->email,
                'total' => (int) ($row->total ?? 0),
                'new' => (int) ($row->new_count ?? 0),
            ];
        })->sortByDesc('total')->take(8)->values()->all();
    }

    /**
     * Последние 8 пересылок (action=forward) — успешные и ошибочные.
     */
    #[Computed]
    public function recentForwards()
    {
        if (! $this->isPrivileged) {
            return collect();
        }

        return RoutedMail::query()
            ->with(['emailMessage:id,subject,from_email', 'rule:id,name'])
            ->where('action_taken', 'forward')
            ->orderByDesc('id')
            ->limit(8)
            ->get();
    }

    /**
     * Foundation §7.3: AI quality score за последние 30 дней.
     * По каждому DetectorType — counts per status + correctness rate.
     * Используется РОПом для решения «включать ли auto-mode для этого типа».
     *
     * correctness = (auto_applied + manually_confirmed) / total_final
     *   total_final = все кроме suggested (текущие pending)
     *
     * @return array<int, array{type: string, label: string, total: int,
     *     auto_applied: int, confirmed: int, overridden: int, dismissed: int,
     *     failed: int, pending: int, correctness: ?float}>
     */
    #[Computed]
    public function aiQualityScore(): array
    {
        if (! $this->isPrivileged) {
            return [];
        }

        $rows = AiDecision::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('detector_type', 'status')
            ->selectRaw('detector_type, status, COUNT(*) as c')
            ->get();

        // Группируем по type → status → count.
        // detector_type / status — enum-cast'ы (DetectorType / AiDecisionStatus),
        // PHP 8.1+ не разрешает enum как ключ массива → используем ->value.
        $byType = [];
        foreach ($rows as $r) {
            $typeKey = $r->detector_type instanceof \BackedEnum ? $r->detector_type->value : (string) $r->detector_type;
            $statusKey = $r->status instanceof \BackedEnum ? $r->status->value : (string) $r->status;
            $byType[$typeKey][$statusKey] = (int) $r->c;
        }

        $out = [];
        foreach (DetectorType::cases() as $type) {
            $stats = $byType[$type->value] ?? [];
            $autoApplied = (int) ($stats[AiDecisionStatus::AutoApplied->value] ?? 0);
            $confirmed = (int) ($stats[AiDecisionStatus::ManuallyConfirmed->value] ?? 0);
            $overridden = (int) ($stats[AiDecisionStatus::ManuallyOverridden->value] ?? 0);
            $dismissed = (int) ($stats[AiDecisionStatus::Dismissed->value] ?? 0);
            $failed = (int) ($stats[AiDecisionStatus::Failed->value] ?? 0);
            $pending = (int) ($stats[AiDecisionStatus::Suggested->value] ?? 0);
            $total = $autoApplied + $confirmed + $overridden + $dismissed + $failed + $pending;
            $totalFinal = $autoApplied + $confirmed + $overridden + $dismissed + $failed;

            $correctness = $totalFinal > 0
                ? round(($autoApplied + $confirmed) * 100 / $totalFinal, 1)
                : null;

            $out[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'total' => $total,
                'auto_applied' => $autoApplied,
                'confirmed' => $confirmed,
                'overridden' => $overridden,
                'dismissed' => $dismissed,
                'failed' => $failed,
                'pending' => $pending,
                'correctness' => $correctness,
            ];
        }

        // Скрываем строки где total=0 (детектор не срабатывал).
        return array_values(array_filter($out, fn ($r) => $r['total'] > 0));
    }

    /**
     * Последние 5 заявок.
     */
    #[Computed]
    public function recentRequests()
    {
        $q = Request::query()
            ->with(['assignedUser:id,name'])
            ->orderByDesc('id')
            ->limit(5);

        if (! $this->isPrivileged) {
            $q->where('assigned_user_id', auth()->id());
        }

        return $q->get();
    }

    /**
     * Воронка за выбранный период: received → quoted → won/lost + conversion.
     *
     * received = заявки с created_at в периоде (новые письма / ручные).
     * quoted/won/lost = уникальные request_id в request_state_changes,
     *   где to_status попал в нужный статус в периоде (события «случились»
     *   в окне, а не «заявка стартовала в окне»).
     *
     * quote_rate = quoted / received  (сколько начатых дошло до КП).
     * conversion = won / (won + lost)  (winrate среди закрытых).
     *
     * @return array{received:int, quoted:int, won:int, lost:int, quote_rate:?float, conversion:?float}
     */
    #[Computed]
    public function funnel(): array
    {
        if (! $this->isPrivileged) {
            return ['received' => 0, 'quoted' => 0, 'won' => 0, 'lost' => 0,
                    'quote_rate' => null, 'conversion' => null];
        }
        $since = $this->periodStart();

        $received = Request::query()
            ->where('created_at', '>=', $since)
            ->count();

        // distinct request_id — заявка могла переходить в Quoted несколько
        // раз (например, через ClientReplied → Quoted снова). Нас интересует
        // «попала ли вообще в КП за период».
        $countByStatus = function (string $status) use ($since): int {
            return RequestStateChange::query()
                ->where('to_status', $status)
                ->where('created_at', '>=', $since)
                ->distinct('request_id')
                ->count('request_id');
        };

        $quoted = $countByStatus(RequestStatus::Quoted->value);
        $won = $countByStatus(RequestStatus::ClosedWon->value);
        $lost = $countByStatus(RequestStatus::ClosedLost->value);

        $quoteRate = $received > 0 ? round($quoted * 100 / $received, 1) : null;
        $closed = $won + $lost;
        $conversion = $closed > 0 ? round($won * 100 / $closed, 1) : null;

        return [
            'received' => $received,
            'quoted' => $quoted,
            'won' => $won,
            'lost' => $lost,
            'quote_rate' => $quoteRate,
            'conversion' => $conversion,
        ];
    }

    /**
     * Inflow heatmap: 7 (Mon-Sun) × 24 (hours, Europe/Moscow) ячеек со
     * счётчиком заявок (requests.created_at) за выбранный период.
     *
     * Используется РОПом увидеть, в какие часы / дни недели приходит
     * больше всего работы — планировать дежурства, нагрузку.
     *
     * @return array{matrix: array<int, array<int, int>>, max: int, total: int}
     */
    #[Computed]
    public function inflowHeatmap(): array
    {
        if (! $this->isPrivileged) {
            return ['matrix' => [], 'max' => 0, 'total' => 0];
        }
        $since = $this->periodStart();

        // Postgres ISODOW: 1=Mon ... 7=Sun. Удобно для русского weekly view.
        $rows = DB::table('requests')
            ->where('created_at', '>=', $since)
            ->selectRaw("
                EXTRACT(ISODOW FROM (created_at AT TIME ZONE 'Europe/Moscow'))::int AS dow,
                EXTRACT(HOUR    FROM (created_at AT TIME ZONE 'Europe/Moscow'))::int AS hr,
                COUNT(*) AS c
            ")
            ->groupBy('dow', 'hr')
            ->get();

        // Инициализируем нулями: 7 строк (Пн..Вс) × 24 колонки (0..23).
        $matrix = [];
        for ($d = 1; $d <= 7; $d++) {
            $matrix[$d] = array_fill(0, 24, 0);
        }
        $max = 0;
        $total = 0;
        foreach ($rows as $r) {
            $d = (int) $r->dow;
            $h = (int) $r->hr;
            $c = (int) $r->c;
            if (! isset($matrix[$d][$h])) {
                continue;
            }
            $matrix[$d][$h] = $c;
            $total += $c;
            if ($c > $max) {
                $max = $c;
            }
        }

        return ['matrix' => $matrix, 'max' => $max, 'total' => $total];
    }

    /**
     * Sparklines per менеджер: ежедневное число НАЗНАЧЕНИЙ за последние
     * 14 дней (всегда 14, независимо от $periodDays — sparkline это «недавняя
     * динамика», период не имеет смысла растягивать).
     *
     * Источник — `request_assignments.created_at` (audit-таблица), а не
     * `requests.assigned_user_id` — последняя показывает только текущий
     * snapshot, а нам нужен поток назначений во времени.
     *
     * @return array<int, array{name: string, email: string, total: int, points: array<int, int>, sum14: int}>
     */
    #[Computed]
    public function managerSparklines(): array
    {
        if (! $this->isPrivileged) {
            return [];
        }
        $managers = User::role(RoleEnum::requestHandlerRoles())->get();
        if ($managers->isEmpty()) {
            return [];
        }

        $days = 14;
        // Boundary: «сегодня по Москве» — последняя ячейка sparkline.
        $startMsk = CarbonImmutable::now('Europe/Moscow')->startOfDay()->subDays($days - 1);

        $rows = DB::table('request_assignments')
            ->whereIn('user_id', $managers->pluck('id'))
            ->where('assigned_at', '>=', $startMsk->utc())
            ->selectRaw("
                user_id,
                DATE(assigned_at AT TIME ZONE 'Europe/Moscow') AS day,
                COUNT(*) AS c
            ")
            ->groupBy('user_id', 'day')
            ->get();

        // user_id → 'Y-m-d' → count
        $byUser = [];
        foreach ($rows as $r) {
            $byUser[(int) $r->user_id][(string) $r->day] = (int) $r->c;
        }

        // current load (для сортировки + чтобы рядом со sparkline видеть)
        $currentLoad = Request::query()
            ->whereIn('assigned_user_id', $managers->pluck('id'))
            ->whereIn('status', array_map(
                fn (RequestStatus $s) => $s->value,
                array_filter(RequestStatus::cases(), fn (RequestStatus $s) => $s->isOpenForAssignment()),
            ))
            ->groupBy('assigned_user_id')
            ->selectRaw('assigned_user_id, COUNT(*) AS c')
            ->pluck('c', 'assigned_user_id')
            ->all();

        $result = [];
        foreach ($managers as $u) {
            $points = [];
            $sum = 0;
            for ($i = 0; $i < $days; $i++) {
                $d = $startMsk->addDays($i)->format('Y-m-d');
                $v = (int) ($byUser[$u->id][$d] ?? 0);
                $points[] = $v;
                $sum += $v;
            }
            $result[] = [
                'name' => $u->name,
                'email' => $u->email,
                'total' => (int) ($currentLoad[$u->id] ?? 0),
                'sum14' => $sum,
                'points' => $points,
            ];
        }

        // Сортируем по текущей нагрузке убыв., затем по sum14.
        usort($result, fn ($a, $b) => ($b['total'] <=> $a['total']) ?: ($b['sum14'] <=> $a['sum14']));

        return $result;
    }

    public function render()
    {
        return view('livewire.dashboard.index');
    }
}
