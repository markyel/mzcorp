<?php

namespace App\Livewire\Reports;

use App\Enums\Role;
use App\Models\User;
use App\Models\WeeklyReport;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * «Еженедельные отчёты» — просмотр персональных отчётов менеджеров.
 * Менеджер видит только СВОИ (включая прошлые недели), РОП/директор/админ —
 * все, с фильтром по менеджеру. Отчёт замороженный (weekly_reports.data),
 * рендерится партиалом reports.weekly. Генерация — reports:weekly-generate.
 */
class Weekly extends Component
{
    #[Url(as: 'r')]
    public ?int $reportId = null;

    /** Фильтр по менеджеру (только для привилегированных). 0 = все. */
    #[Url(as: 'mgr')]
    public int $viewUser = 0;

    public function mount(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole([
                Role::Manager->value, Role::HeadOfSales->value, Role::Director->value, Role::Admin->value,
            ]),
            403,
        );
        if ($this->reportId === null) {
            $this->reportId = $this->reports->first()?->id;
        }
    }

    public function updatedViewUser(): void
    {
        unset($this->reports);
        $this->reportId = $this->reports->first()?->id;
    }

    private function isPrivileged(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([
            Role::HeadOfSales->value, Role::Director->value, Role::Admin->value,
        ]);
    }

    /** Доступные отчёты (метаданные для списка). @return Collection<int, WeeklyReport> */
    #[Computed]
    public function reports(): Collection
    {
        $q = WeeklyReport::query()->with('user:id,name')
            ->orderByDesc('period_start')->orderBy('user_id');

        if ($this->isPrivileged()) {
            if ($this->viewUser > 0) {
                $q->where('user_id', $this->viewUser);
            }
        } else {
            $q->where('user_id', auth()->id());
        }

        return $q->limit(400)->get(['id', 'user_id', 'period_start', 'period_end', 'data']);
    }

    /** Выбранный отчёт (с проверкой доступа). */
    #[Computed]
    public function report(): ?WeeklyReport
    {
        if ($this->reportId === null) {
            return null;
        }
        $r = WeeklyReport::find($this->reportId);
        if ($r === null) {
            return null;
        }
        if (! $this->isPrivileged() && (int) $r->user_id !== (int) auth()->id()) {
            return null;
        }

        return $r;
    }

    /** Менеджеры для фильтра (у кого есть отчёты) — привилегированным. */
    #[Computed]
    public function managerOptions(): array
    {
        if (! $this->isPrivileged()) {
            return [];
        }
        $ids = WeeklyReport::query()->distinct()->pluck('user_id')->all();

        return User::whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all();
    }

    public function render()
    {
        return view('livewire.reports.weekly');
    }
}
