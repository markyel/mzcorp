<?php

namespace App\Livewire\Dashboard;

use App\Enums\EmailClassification;
use App\Enums\RequestStatus;
use App\Enums\Role as RoleEnum;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Request;
use App\Models\RoutedMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
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

        return compact('total', 'new', 'assigned', 'unassigned', 'today', 'week');
    }

    /**
     * Распределение AI-классификации последних 30 дней.
     *
     * @return array<int, array{class: string, label: string, count: int}>
     */
    #[Computed]
    public function aiBreakdown(): array
    {
        $rows = EmailMessage::query()
            ->where('direction', 'inbound')
            ->whereNotNull('ai_classification')
            ->where('classified_at', '>=', now()->subDays(30))
            ->groupBy('ai_classification')
            ->selectRaw('ai_classification AS class, COUNT(*) AS c')
            ->orderByDesc('c')
            ->get();

        return $rows->map(function ($r) {
            $enum = EmailClassification::tryFrom($r->class);

            return [
                'class' => $r->class,
                'label' => $enum?->label() ?? $r->class,
                'count' => (int) $r->c,
            ];
        })->all();
    }

    /**
     * Какой % писем за 30 дней успешно классифицирован.
     */
    #[Computed]
    public function aiCoverage(): array
    {
        $total = EmailMessage::where('direction', 'inbound')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
        $classified = EmailMessage::where('direction', 'inbound')
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('classified_at')
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

        $managers = User::role(RoleEnum::Manager->value)->get();
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

    public function render()
    {
        return view('livewire.dashboard.index');
    }
}
