<?php

namespace App\Livewire\Admin\Managers;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use App\Services\Request\ManagerOffboardingService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Диалог «Отключить менеджера»: сотрудник уходит насовсем. Показывает, что
 * будет с его заявками и делегированиями, даёт выбрать — раздать между
 * доступными менеджерами или отдать одному, — и по подтверждению вызывает
 * ManagerOffboardingService. Слушает `open-offboard {userId}`.
 */
class OffboardDialog extends Component
{
    public ?int $userId = null;

    public bool $open = false;

    /** 'spread' — раздать между доступными; 'one' — всё одному. */
    public string $mode = 'spread';

    public ?int $heirId = null;

    public string $comment = '';

    #[On('open-offboard')]
    public function show(int $userId): void
    {
        $this->userId = $userId;
        $this->mode = 'spread';
        $this->heirId = null;
        $this->comment = '';
        $this->resetErrorBag();
        unset($this->preview);
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
        $this->userId = null;
    }

    public function updatedMode(): void
    {
        unset($this->preview);
    }

    public function updatedHeirId(): void
    {
        unset($this->preview);
    }

    #[Computed]
    public function leaver(): ?User
    {
        return $this->userId ? User::query()->find($this->userId) : null;
    }

    /** @return array<string, mixed>|null */
    #[Computed]
    public function preview(): ?array
    {
        $leaver = $this->leaver;
        if ($leaver === null) {
            return null;
        }
        $svc = app(ManagerOffboardingService::class);
        $heir = $this->mode === 'one' && $this->heirId ? User::query()->find($this->heirId) : null;

        return [
            'candidates' => $svc->candidates($leaver),
            'plan' => $this->mode === 'one' && $heir === null ? null : $svc->plan($leaver, $heir),
            'delegations' => $svc->delegations($leaver),
            'open' => $svc->openRequests($leaver)->count(),
        ];
    }

    public function confirm(ManagerOffboardingService $svc): void
    {
        $by = auth()->user();
        $leaver = $this->leaver;
        if ($by === null || $leaver === null) {
            return;
        }
        if (! $by->hasAnyRole([RoleEnum::HeadOfSales->value, RoleEnum::Director->value, RoleEnum::Admin->value])) {
            abort(403);
        }
        if ($leaver->id === $by->id) {
            $this->addError('heirId', 'Нельзя отключить собственную учётку.');

            return;
        }
        if ($leaver->hasRole(RoleEnum::Admin->value) && ! $by->hasRole(RoleEnum::Admin->value)) {
            $this->addError('heirId', 'Админ-учётку отключает только администратор.');

            return;
        }

        $heir = null;
        if ($this->mode === 'one') {
            $heir = $this->preview['candidates']->firstWhere('id', $this->heirId);
            if ($heir === null) {
                $this->addError('heirId', 'Выберите, кому передать заявки.');

                return;
            }
        } elseif (($this->preview['plan']['requests'] ?? 0) > 0 && ($this->preview['plan']['assign'] ?? []) === []) {
            $this->addError('heirId', 'Нет доступных менеджеров, чтобы раздать заявки. Выберите одного вручную.');

            return;
        }

        $res = $svc->offboard($leaver, $heir, $by, trim($this->comment) ?: null);

        $msg = "«{$leaver->name}» отключён: заявок передано {$res['reassigned']}"
            .($res['failed'] ? ", не удалось {$res['failed']} — см. журнал" : '')
            .($res['delegations_closed'] ? ", делегирований закрыто {$res['delegations_closed']}" : '')
            .($res['redelegated'] ? ", {$res['redelegated']} заявок отсутствующих коллег открыты другим" : '')
            .'. Вход и личные ящики отключены.';
        session()->flash('status', $msg);
        $this->open = false;
        $this->dispatch('manager-availability-changed');
    }

    public function render()
    {
        return view('livewire.admin.managers.offboard-dialog');
    }
}
