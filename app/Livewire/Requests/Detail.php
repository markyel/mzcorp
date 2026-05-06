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
            'emailMessage.attachments:id,email_message_id,filename,size_bytes,mime_type,content_id',
            'emailMessage.mailbox:id,email,name',
            'assignments.user:id,name',
            'assignments.assignedBy:id,name',
        ]);
    }

    /**
     * Заменить cid:NNN в src/href HTML body на наш inline-роут.
     */
    public function bodyHtml(): ?string
    {
        $email = $this->request->emailMessage;
        if (! $email || ! $email->body_html) {
            return null;
        }

        $html = $email->body_html;
        $messageId = $email->id;

        // src="cid:..."  и  src='cid:...'
        return preg_replace_callback(
            '/(src|href)\s*=\s*(["\'])cid:([^"\']+)\2/i',
            function ($m) use ($messageId) {
                $url = route('attachments.inline', [
                    'emailMessage' => $messageId,
                    'contentId' => rawurlencode($m[3]),
                ]);

                return $m[1] . '=' . $m[2] . $url . $m[2];
            },
            $html
        );
    }

    public function render()
    {
        return view('livewire.requests.detail');
    }
}
