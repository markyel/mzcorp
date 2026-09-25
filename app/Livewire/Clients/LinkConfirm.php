<?php

namespace App\Livewire\Clients;

use App\Models\OrganizationLinkRequest;
use App\Services\Clients\OrganizationLinkGuard;
use Livewire\Component;

/**
 * Страница подтверждения сомнительной привязки контрагента к адресу заказчика
 * (ссылка из письма OrganizationLinkPendingMail). Решает менеджер, которому
 * пришло письмо, РОП, директор или админ; остальные видят только суть.
 */
class LinkConfirm extends Component
{
    public OrganizationLinkRequest $linkRequest;

    public function mount(OrganizationLinkRequest $linkRequest): void
    {
        abort_unless(auth()->check(), 403);
        $this->linkRequest = $linkRequest->load([
            'organization', 'contact', 'request:id,internal_code,subject,client_email',
            'notifiedUser:id,name', 'decidedBy:id,name',
        ]);
    }

    public function confirm(OrganizationLinkGuard $guard): void
    {
        if (! $this->mayDecide($guard)) {
            return;
        }
        $linked = $guard->confirm($this->linkRequest, auth()->user());
        $this->linkRequest->refresh();
        $this->dispatch('toast', message: 'Привязка подтверждена'.($linked > 0 ? ', заявок привязано: '.$linked : '').'.', type: 'success');
    }

    public function reject(OrganizationLinkGuard $guard): void
    {
        if (! $this->mayDecide($guard)) {
            return;
        }
        $guard->reject($this->linkRequest, auth()->user());
        $this->linkRequest->refresh();
        $this->dispatch('toast', message: 'Отмечено как ошибка — привязки не будет.', type: 'success');
    }

    private function mayDecide(OrganizationLinkGuard $guard): bool
    {
        if (! $this->linkRequest->isPending()) {
            $this->dispatch('toast', message: 'Решение по этой привязке уже принято.', type: 'error');

            return false;
        }
        if (! $guard->canDecide($this->linkRequest, auth()->user())) {
            $this->dispatch('toast', message: 'Решить может менеджер, выдавший документ, РОП или директор.', type: 'error');

            return false;
        }

        return true;
    }

    public function render(OrganizationLinkGuard $guard)
    {
        return view('livewire.clients.link-confirm', [
            'canDecide' => $guard->canDecide($this->linkRequest, auth()->user()),
        ]);
    }
}
