<?php

namespace App\Livewire\Requests;

use App\Enums\Role;
use App\Models\Request;
use Livewire\Component;

/**
 * Карточка заявки.
 *
 * Менеджер видит только свои. РОП/директор/секретарь — все.
 * Phase 1.10 — read-only представление: код, клиент, исходное письмо
 * (parsed body), вложения, история назначений.
 */
class Detail extends Component
{
    public Request $request;

    public function mount(Request $request): void
    {
        // Простой access-control: менеджер видит только свои.
        $user = auth()->user();
        $isPrivileged = $user?->hasAnyRole([
            Role::HeadOfSales->value,
            Role::Director->value,
            Role::Secretary->value,
        ]);

        if (! $isPrivileged && $request->assigned_user_id !== $user?->id) {
            abort(403, 'Эта заявка назначена другому менеджеру.');
        }

        $this->request = $request->load([
            'assignedUser:id,name,email',
            'emailMessage.attachments:id,email_message_id,filename,size_bytes,mime_type',
            'emailMessage.mailbox:id,email,name',
            'assignments.user:id,name',
            'assignments.assignedBy:id,name',
        ]);
    }

    public function render()
    {
        return view('livewire.requests.detail');
    }
}
