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
     * Период (в днях) для funnel, conversion, heatmap.
     * Переключается чипами 1/7/30/90 в шапке dashboard.
     * `1` = сегодня с 00:00 МСК; 7/30/90 = `subDays(N)..now()`.
     * Если customFrom + customTo заданы — они override'ят preset.
     */
    #[Url(as: 'period', except: 30)]
    public int $periodDays = 30;

    /**
     * Произвольный период: дата начала (Y-m-d, локально МСК).
     * Когда оба customFrom + customTo заданы — игнорируем periodDays.
     */
    #[Url(as: 'from', except: '')]
    public string $customFrom = '';

    /**
     * Произвольный период: дата окончания (Y-m-d, включительно).
     */
    #[Url(as: 'to', except: '')]
    public string $customTo = '';

    /**
     * Флаг для UI: раскрыт ли date-range picker для custom-периода.
     * Не персистится в URL (cosmetic state).
     */
    public bool $customPickerOpen = false;

    public function setPeriod(int $days): void
    {
        if (in_array($days, [1, 7, 30, 90], true)) {
            $this->periodDays = $days;
            // Переключение на preset очищает custom.
            $this->customFrom = '';
            $this->customTo = '';
            $this->customPickerOpen = false;
        }
    }

    public function toggleCustomPicker(): void
    {
        $this->customPickerOpen = ! $this->customPickerOpen;
        if ($this->customPickerOpen && $this->customFrom === '' && $this->customTo === '') {
            // Префилл: последние 14 дней, чтобы стартовать с разумного диапазона.
            $today = CarbonImmutable::now('Europe/Moscow');
            $this->customFrom = $today->subDays(13)->format('Y-m-d');
            $this->customTo = $today->format('Y-m-d');
        }
    }

    public function applyCustomPeriod(): void
    {
        // Защита: from <= to, оба валидны.
        try {
            $from = CarbonImmutable::createFromFormat('Y-m-d', $this->customFrom, 'Europe/Moscow');
            $to = CarbonImmutable::createFromFormat('Y-m-d', $this->customTo, 'Europe/Moscow');
            if (! $from || ! $to || $from->gt($to)) {
                throw new \InvalidArgumentException('bad range');
            }
            $this->customPickerOpen = false;
        } catch (\Throwable $e) {
            // Не применяем — оставляем picker открытым, dashboard продолжает
            // показывать данные по текущему periodDays.
            $this->customFrom = '';
            $this->customTo = '';
        }
    }

    public function clearCustomPeriod(): void
    {
        $this->customFrom = '';
        $this->customTo = '';
        $this->customPickerOpen = false;
    }

    /**
     * Активен ли custom-период (оба значения валидны).
     */
    #[Computed]
    public function isCustomPeriod(): bool
    {
        return $this->customFrom !== '' && $this->customTo !== '';
    }

    /**
     * Текст для подписи периода в заголовках виджетов («Воронка · ...»).
     */
    #[Computed]
    public function periodLabel(): string
    {
        if ($this->isCustomPeriod) {
            try {
                $from = CarbonImmutable::createFromFormat('Y-m-d', $this->customFrom);
                $to = CarbonImmutable::createFromFormat('Y-m-d', $this->customTo);
                return $from->format('d.m') . ' – ' . $to->format('d.m.Y');
            } catch (\Throwable) {
                return 'произвольный';
            }
        }
        return $this->periodDays === 1
            ? 'сегодня'
            : $this->periodDays . ' дн.';
    }

    /**
     * Начало периода — для совместимости с старым кодом, дёрнет periodRange()[0].
     */
    private function periodStart(): CarbonImmutable
    {
        return $this->periodRange()[0];
    }

    /**
     * Диапазон периода [from, to) для where between.
     *  - custom: customFrom 00:00 МСК → customTo+1 00:00 МСК (включает весь день to)
     *  - preset 1: сегодня 00:00 МСК → now (календарный «сегодня»)
     *  - preset 7/30/90: now-N → now (rolling)
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function periodRange(): array
    {
        if ($this->isCustomPeriod) {
            try {
                $from = CarbonImmutable::createFromFormat('Y-m-d', $this->customFrom, 'Europe/Moscow')
                    ->startOfDay();
                $to = CarbonImmutable::createFromFormat('Y-m-d', $this->customTo, 'Europe/Moscow')
                    ->endOfDay();
                return [$from, $to];
            } catch (\Throwable) {
                // fallthrough to preset
            }
        }
        if ($this->periodDays === 1) {
            return [
                CarbonImmutable::now('Europe/Moscow')->startOfDay(),
                CarbonImmutable::now('Europe/Moscow'),
            ];
        }
        return [
            CarbonImmutable::now()->subDays($this->periodDays),
            CarbonImmutable::now(),
        ];
    }

    /**
     * Режим менеджерской таблицы.
     *   current   — снимок текущей нагрузки (default, без period)
     *   today     — назначено сегодня от 00:00 МСК (1 точка sparkline)
     *   yesterday — назначено вчера 00:00..23:59 МСК (1 точка)
     *   custom    — назначено за custom-диапазон по mgr_from / mgr_to
     *
     * Default `current` показывает снимок (активные/слжн/hard/всего/info@).
     * Остальные три — period-табличку (назначено/info@-период/sparkline).
     */
    #[Url(as: 'mgr_mode', except: 'current')]
    public string $sparklineMode = 'current';

    /**
     * Начало кастомного периода для sparkline (Y-m-d, МСК).
     */
    #[Url(as: 'mgr_from', except: '')]
    public string $sparklineFrom = '';

    /**
     * Конец кастомного периода для sparkline (включительно).
     */
    #[Url(as: 'mgr_to', except: '')]
    public string $sparklineTo = '';

    /**
     * Раскрыт ли inline date-picker для custom-периода sparkline'а.
     */
    public bool $sparklinePickerOpen = false;

    public function setSparklineMode(string $mode): void
    {
        if (in_array($mode, ['current', 'today', 'yesterday'], true)) {
            $this->sparklineMode = $mode;
            $this->sparklineFrom = '';
            $this->sparklineTo = '';
            $this->sparklinePickerOpen = false;
        }
    }

    public function toggleSparklinePicker(): void
    {
        $this->sparklinePickerOpen = ! $this->sparklinePickerOpen;
        if ($this->sparklinePickerOpen && $this->sparklineFrom === '' && $this->sparklineTo === '') {
            // Префилл: последние 14 дней.
            $today = CarbonImmutable::now('Europe/Moscow');
            $this->sparklineFrom = $today->subDays(13)->format('Y-m-d');
            $this->sparklineTo = $today->format('Y-m-d');
        }
    }

    public function applySparklinePeriod(): void
    {
        try {
            $from = CarbonImmutable::createFromFormat('Y-m-d', $this->sparklineFrom, 'Europe/Moscow');
            $to = CarbonImmutable::createFromFormat('Y-m-d', $this->sparklineTo, 'Europe/Moscow');
            if (! $from || ! $to || $from->gt($to)) {
                throw new \InvalidArgumentException('bad range');
            }
            $this->sparklineMode = 'custom';
            $this->sparklinePickerOpen = false;
        } catch (\Throwable) {
            $this->sparklineFrom = '';
            $this->sparklineTo = '';
        }
    }

    /**
     * Подпись текущего sparkline-периода для UI (заголовок карточки,
     * tooltip колонки).
     */
    #[Computed]
    public function sparklineLabel(): string
    {
        if ($this->sparklineMode === 'current') {
            return 'текущая загрузка';
        }
        if ($this->sparklineMode === 'today') {
            return 'сегодня';
        }
        if ($this->sparklineMode === 'yesterday') {
            return 'вчера';
        }
        if ($this->sparklineMode === 'custom' && $this->sparklineFrom !== '' && $this->sparklineTo !== '') {
            try {
                $from = CarbonImmutable::createFromFormat('Y-m-d', $this->sparklineFrom);
                $to = CarbonImmutable::createFromFormat('Y-m-d', $this->sparklineTo);
                return $from->format('d.m') . ' – ' . $to->format('d.m.Y');
            } catch (\Throwable) {
                // fallthrough
            }
        }
        return 'текущая загрузка';
    }

    /**
     * Диапазон дней [startMsk, days] для построения sparkline.
     *   - today  → [today 00:00, 1 day]
     *   - yesterday → [yesterday 00:00, 1 day]
     *   - custom → [from 00:00, ceil(to - from) + 1 days]
     *
     * @return array{0: CarbonImmutable, 1: int}  start (МСК 00:00), кол-во дней
     */
    private function sparklineWindow(): array
    {
        $todayMsk = CarbonImmutable::now('Europe/Moscow')->startOfDay();
        if ($this->sparklineMode === 'yesterday') {
            return [$todayMsk->subDay(), 1];
        }
        if ($this->sparklineMode === 'custom' && $this->sparklineFrom !== '' && $this->sparklineTo !== '') {
            try {
                $from = CarbonImmutable::createFromFormat('Y-m-d', $this->sparklineFrom, 'Europe/Moscow')->startOfDay();
                $to = CarbonImmutable::createFromFormat('Y-m-d', $this->sparklineTo, 'Europe/Moscow')->startOfDay();
                if ($from->lte($to)) {
                    $days = $from->diffInDays($to) + 1;
                    // Защита от слишком длинных диапазонов в sparkline (UI cramps на 100+).
                    $days = (int) min(366, max(1, $days));
                    return [$from, $days];
                }
            } catch (\Throwable) {
                // fallthrough to today
            }
        }
        // today (default)
        return [$todayMsk, 1];
    }

    #[Computed]
    public function isPrivileged(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([
            RoleEnum::HeadOfSales->value,
            RoleEnum::Director->value,
            RoleEnum::Secretary->value,
            RoleEnum::Admin->value,
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

        $managers = User::active()->role(RoleEnum::requestHandlerRoles())->get();
        if ($managers->isEmpty()) {
            return [];
        }

        // Phase complexity: добавляем SUM(complexity_score) — суммарная
        // нагрузка менеджера, и hard_count (hard + very_hard active заявки).
        // Это даёт более точную картину чем «просто число заявок» — 5
        // заявок A/B-сложности легче чем 1 very_hard с 8 unmatched.
        $active = [
            RequestStatus::New->value,
            RequestStatus::Assigned->value,
            RequestStatus::InProgress->value,
            RequestStatus::AwaitingClientClarification->value,
            RequestStatus::Quoted->value,
            RequestStatus::UnderReview->value,
            RequestStatus::PostponedUntil->value,
            RequestStatus::AwaitingInvoice->value,
            RequestStatus::Invoiced->value,
            RequestStatus::Paid->value,
        ];
        $loads = Request::query()
            ->whereIn('assigned_user_id', $managers->pluck('id'))
            ->groupBy('assigned_user_id')
            ->selectRaw("
                assigned_user_id,
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = '" . RequestStatus::New->value . "') AS new_count,
                COALESCE(SUM(complexity_score) FILTER (WHERE status IN ('" . implode("','", $active) . "')), 0) AS active_complexity,
                COUNT(*) FILTER (WHERE complexity_level IN ('hard', 'very_hard') AND status IN ('" . implode("','", $active) . "')) AS hard_count
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
                'active_complexity' => (int) ($row->active_complexity ?? 0),
                'hard_count' => (int) ($row->hard_count ?? 0),
            ];
        })->sortByDesc('active_complexity')->take(8)->values()->all();
    }

    /**
     * Phase complexity: KPI «Сложных в работе» — hard + very_hard заявки
     * в активных статусах. Для менеджера — свои; для РОП — всех.
     *
     * @return array{hard: int, very_hard: int, total_active: int}
     */
    #[Computed]
    public function complexityKpi(): array
    {
        $active = [
            RequestStatus::New->value,
            RequestStatus::Assigned->value,
            RequestStatus::InProgress->value,
            RequestStatus::AwaitingClientClarification->value,
            RequestStatus::Quoted->value,
            RequestStatus::UnderReview->value,
            RequestStatus::PostponedUntil->value,
            RequestStatus::AwaitingInvoice->value,
            RequestStatus::Invoiced->value,
            RequestStatus::Paid->value,
        ];
        $base = Request::query()->whereIn('status', $active);
        if (! $this->isPrivileged) {
            $base->where('assigned_user_id', auth()->id());
        }

        $rows = (clone $base)
            ->selectRaw('complexity_level, COUNT(*) AS c')
            ->groupBy('complexity_level')
            ->pluck('c', 'complexity_level');

        return [
            'hard' => (int) ($rows['hard'] ?? 0),
            'very_hard' => (int) ($rows['very_hard'] ?? 0),
            'total_active' => (int) (clone $base)->count(),
        ];
    }

    /**
     * Phase complexity: разбивка active items по match_path × has_photo.
     * Показывает «откуда приходят позиции» — сколько с M-артикулом vs
     * нужно разбирать руками, и какая доля имеет фото для подсказки.
     *
     * @return array<int, array{path: string, label: string, with_photo: int, no_photo: int, total: int, weight: int}>
     */
    #[Computed]
    public function complexityBreakdown(): array
    {
        $active = [
            RequestStatus::New->value,
            RequestStatus::Assigned->value,
            RequestStatus::InProgress->value,
            RequestStatus::AwaitingClientClarification->value,
            RequestStatus::Quoted->value,
            RequestStatus::UnderReview->value,
            RequestStatus::PostponedUntil->value,
            RequestStatus::AwaitingInvoice->value,
            RequestStatus::Invoiced->value,
            RequestStatus::Paid->value,
        ];

        $rows = DB::table('request_items as ri')
            ->join('requests as r', 'r.id', '=', 'ri.request_id')
            ->where('ri.is_active', true)
            ->whereIn('r.status', $active);
        if (! $this->isPrivileged) {
            $rows->where('r.assigned_user_id', auth()->id());
        }
        $aggregated = $rows
            ->groupBy('ri.match_path')
            ->selectRaw('
                ri.match_path AS path,
                COUNT(*) FILTER (WHERE ri.image_attachment_id IS NOT NULL) AS with_photo,
                COUNT(*) FILTER (WHERE ri.image_attachment_id IS NULL) AS no_photo,
                COUNT(*) AS total
            ')
            ->get()
            ->keyBy('path');

        return collect(\App\Enums\MatchPath::cases())->map(function ($mp) use ($aggregated) {
            $row = $aggregated->get($mp->value);
            return [
                'path' => $mp->value,
                'label' => $mp->label(),
                'icon' => $mp->icon(),
                'with_photo' => (int) ($row->with_photo ?? 0),
                'no_photo' => (int) ($row->no_photo ?? 0),
                'total' => (int) ($row->total ?? 0),
                'weight' => $mp->defaultWeight(),
            ];
        })->all();
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
        [$from, $to] = $this->periodRange();

        $received = Request::query()
            ->whereBetween('created_at', [$from, $to])
            ->count();

        // distinct request_id — заявка могла переходить в Quoted несколько
        // раз (например, через ClientReplied → Quoted снова). Нас интересует
        // «попала ли вообще в КП за период».
        $countByStatus = function (string $status) use ($from, $to): int {
            return RequestStateChange::query()
                ->where('to_status', $status)
                ->whereBetween('created_at', [$from, $to])
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
        [$from, $to] = $this->periodRange();

        // Postgres ISODOW: 1=Mon ... 7=Sun. Удобно для русского weekly view.
        $rows = DB::table('requests')
            ->whereBetween('created_at', [$from, $to])
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
     * Снимок текущей нагрузки менеджера: число активных заявок прямо
     * сейчас, complexity_score sum, hard_count, all-time totals.
     * **Не зависит от period** — это «фото на сейчас».
     *
     * Используется для карточки «Менеджеры · нагрузка сейчас».
     *
     * @return array<int, array{name:string, email:string, active:int,
     *     active_complexity:int, hard_count:int, total_all_time:int,
     *     from_info_total:int}>
     */
    #[Computed]
    public function managersCurrentLoad(): array
    {
        if (! $this->isPrivileged) {
            return [];
        }
        $managers = User::active()->role(RoleEnum::requestHandlerRoles())->get();
        if ($managers->isEmpty()) {
            return [];
        }

        $activeStatuses = array_map(
            fn (RequestStatus $s) => $s->value,
            array_filter(RequestStatus::cases(), fn (RequestStatus $s) => $s->isOpenForAssignment()),
        );
        $currentLoad = Request::query()
            ->whereIn('assigned_user_id', $managers->pluck('id'))
            ->whereIn('status', $activeStatuses)
            ->groupBy('assigned_user_id')
            ->selectRaw('
                assigned_user_id,
                COUNT(*) AS c,
                COALESCE(SUM(complexity_score), 0) AS active_complexity,
                COUNT(*) FILTER (WHERE complexity_level IN (\'hard\', \'very_hard\')) AS hard_count
            ')
            ->get()
            ->keyBy('assigned_user_id');

        $infoMailboxId = \App\Models\Mailbox::query()
            ->whereRaw('LOWER(email) = ?', ['info@myzip.ru'])
            ->value('id');

        $totalAllTime = Request::query()
            ->whereIn('assigned_user_id', $managers->pluck('id'))
            ->groupBy('assigned_user_id')
            ->selectRaw('assigned_user_id, COUNT(*) AS c')
            ->get()
            ->keyBy('assigned_user_id');

        $totalFromInfo = collect();
        if ($infoMailboxId !== null) {
            $totalFromInfo = Request::query()
                ->whereIn('assigned_user_id', $managers->pluck('id'))
                ->whereHas('emailMessage', fn ($q) => $q->where('mailbox_id', $infoMailboxId))
                ->groupBy('assigned_user_id')
                ->selectRaw('assigned_user_id, COUNT(*) AS c')
                ->get()
                ->keyBy('assigned_user_id');
        }

        $result = [];
        foreach ($managers as $u) {
            $row = $currentLoad->get($u->id);
            $result[] = [
                'name' => $u->name,
                'email' => $u->email,
                'active' => (int) ($row->c ?? 0),
                'active_complexity' => (int) ($row->active_complexity ?? 0),
                'hard_count' => (int) ($row->hard_count ?? 0),
                'total_all_time' => (int) ($totalAllTime->get($u->id)?->c ?? 0),
                'from_info_total' => (int) ($totalFromInfo->get($u->id)?->c ?? 0),
            ];
        }

        // Сортировка: по активной нагрузке убыв., затем по complexity.
        usort($result, fn ($a, $b) => ($b['active'] <=> $a['active'])
            ?: ($b['active_complexity'] <=> $a['active_complexity']));

        return $result;
    }

    /**
     * Назначено per менеджер за выбранный период (sparkline + numerical sum).
     * **Зависит от sparklineMode** (today / yesterday / custom).
     *
     * Источник — `request_assignments` (audit). Считаем число событий
     * назначения В ОКНЕ + sparkline-точки по дням. Дополнительно:
     * сколько из них через info@ shared ящик за тот же период.
     *
     * @return array<int, array{name:string, email:string, assigned:int,
     *     from_info_period:int, points:array<int,int>}>
     */
    #[Computed]
    public function managersAssignedInPeriod(): array
    {
        if (! $this->isPrivileged) {
            return [];
        }
        $managers = User::active()->role(RoleEnum::requestHandlerRoles())->get();
        if ($managers->isEmpty()) {
            return [];
        }

        [$startMsk, $days] = $this->sparklineWindow();
        // Граница ВЕРХНЯЯ — start + days (exclusive). Для today/yesterday
        // отрезает «всё что после конца окна», иначе вчерашний sparkline
        // будет ловить и сегодняшние назначения.
        $endMsk = $startMsk->addDays($days);

        $rows = DB::table('request_assignments')
            ->whereIn('user_id', $managers->pluck('id'))
            ->whereBetween('assigned_at', [$startMsk->utc(), $endMsk->utc()])
            ->selectRaw("
                user_id,
                DATE(assigned_at AT TIME ZONE 'Europe/Moscow') AS day,
                COUNT(*) AS c
            ")
            ->groupBy('user_id', 'day')
            ->get();

        $byUser = [];
        foreach ($rows as $r) {
            $byUser[(int) $r->user_id][(string) $r->day] = (int) $r->c;
        }

        // info@ через тот же window: связываем request_assignment.request_id
        // → requests.email_message_id → email_messages.mailbox_id.
        $infoMailboxId = \App\Models\Mailbox::query()
            ->whereRaw('LOWER(email) = ?', ['info@myzip.ru'])
            ->value('id');

        $fromInfoPeriod = collect();
        if ($infoMailboxId !== null) {
            $fromInfoPeriod = DB::table('request_assignments as ra')
                ->join('requests as r', 'r.id', '=', 'ra.request_id')
                ->join('email_messages as em', 'em.id', '=', 'r.email_message_id')
                ->whereIn('ra.user_id', $managers->pluck('id'))
                ->whereBetween('ra.assigned_at', [$startMsk->utc(), $endMsk->utc()])
                ->where('em.mailbox_id', $infoMailboxId)
                ->groupBy('ra.user_id')
                ->selectRaw('ra.user_id, COUNT(*) AS c')
                ->get()
                ->keyBy('user_id');
        }

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
                'assigned' => $sum,
                'from_info_period' => (int) ($fromInfoPeriod->get($u->id)?->c ?? 0),
                'points' => $points,
            ];
        }

        // Сортировка: по числу назначений убыв.
        usort($result, fn ($a, $b) => $b['assigned'] <=> $a['assigned']);

        return $result;
    }

    public function render()
    {
        return view('livewire.dashboard.index');
    }
}
