<?php

namespace App\Livewire\Requests;

use App\Enums\Role as RoleEnum;
use App\Models\Request as RequestModel;
use App\Models\User;
use App\Services\Request\ReassignService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Модалка ручного переподчинения заявки (Phase 1.13).
 *
 * Открывается из карточки заявки кнопкой «⊘ Переподчинить» — доступна только
 * РОПу/директору/секретарю. Менеджер свою заявку через эту модалку не передаёт
 * (это будет отдельный flow «передать другому» в Phase 2).
 *
 * После save() → ReassignService::reassign() → emit('request-reassigned')
 * родительский Detail компонент перерисует таб «Активность» и hero-блок.
 */
class ReassignDialog extends Component
{
    /**
     * Храним только id заявки, не Eloquent-модель: Livewire-дегидратация
     * `App\Models\Request` ловила shadow от `Illuminate\Http\Request` и
     * валила render с 500 на child-компоненте.
     */
    public int $requestId;

    public bool $open = false;
    public ?int $newAssigneeId = null;
    public string $reason = '';

    public function mount(RequestModel $request): void
    {
        $this->requestId = $request->id;
    }

    private function request(): RequestModel
    {
        return RequestModel::findOrFail($this->requestId);
    }

    public function show(): void
    {
        // Сброс состояния перед открытием.
        $this->newAssigneeId = null;
        $this->reason = '';
        $this->resetErrorBag();
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function save(ReassignService $service)
    {
        $this->ensureAuthorized();

        if (! $this->newAssigneeId) {
            $this->addError('newAssigneeId', 'Выберите менеджера.');

            return null;
        }

        $newAssignee = User::query()
            ->role(RoleEnum::requestHandlerRoles())
            ->active()
            ->whereKey($this->newAssigneeId)
            ->first();

        if (! $newAssignee) {
            $this->addError('newAssigneeId', 'Менеджер не найден или в архиве.');

            return null;
        }

        $request = $this->request();

        if ($newAssignee->id === $request->assigned_user_id) {
            $this->addError('newAssigneeId', 'Заявка уже назначена на этого менеджера.');

            return null;
        }

        $service->reassign(
            request: $request,
            newAssignee: $newAssignee,
            reason: trim($this->reason) ?: null,
            by: auth()->user(),
        );

        $this->open = false;
        session()->flash('status', "Заявка переподчинена: {$newAssignee->name}.");

        // Перезагрузка родителя (Detail::mount() заново вытащит assignments).
        return $this->redirect(
            \Illuminate\Support\Facades\Request::header('Referer')
                ?: route('requests.show', $request),
            navigate: true,
        );
    }

    private function ensureAuthorized(): void
    {
        $user = auth()->user();
        $allowed = $user && $user->hasAnyRole([
            RoleEnum::HeadOfSales->value,
            RoleEnum::Director->value,
            RoleEnum::Secretary->value,
        ]);
        if (! $allowed) {
            abort(403);
        }
    }

    #[Computed]
    public function managers()
    {
        return User::query()
            ->role(RoleEnum::requestHandlerRoles())
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    public function render()
    {
        return view('livewire.requests.reassign-dialog', [
            'request' => $this->request(),
        ]);
    }
}
