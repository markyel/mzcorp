<?php

namespace App\Livewire\Requests;

use App\Enums\Role;
use App\Models\Request;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Карточка заявки (Phase 1.8d).
 *
 * Менеджер видит только свои; РОП/директор/секретарь — все.
 *
 * UI разбит на 7 табов по `design/ui_kits/crm/04-request-detail.html`:
 * Обзор / Переписка / Позиции / Поставщики / Активность / Файлы / Связанные.
 *
 * Поля sticky/SLA/сумма/сматчено и табы Поставщики/Связанные пока
 * рендерят placeholder «Phase 2», т.к. данных в БД ещё нет.
 */
class Detail extends Component
{
    public const TABS = ['overview', 'thread', 'items', 'suppliers', 'activity', 'files', 'related'];

    public Request $request;

    #[Url(as: 'tab')]
    public string $tab = 'overview';

    public function mount(Request $request): void
    {
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
            'items',
            'emailMessage.attachments:id,email_message_id,filename,size_bytes,mime_type,content_id,is_inline',
            'emailMessage.mailbox:id,email,name',
            'assignments' => fn ($q) => $q->orderByDesc('assigned_at'),
            'assignments.user:id,name',
            'assignments.assignedBy:id,name',
        ]);

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    /**
     * Подписи и счётчики для табов. `null` = без счётчика.
     *
     * @return array<string, array{label: string, count: ?int, disabled: bool}>
     */
    #[Computed]
    public function tabs(): array
    {
        $thread = $this->request->emailMessage ? 1 : 0;
        $items = $this->request->items->count();
        $files = $this->request->emailMessage?->attachments->count() ?? 0;
        $activity = $this->request->assignments->count() + 1; // +1 за событие создания

        return [
            'overview'  => ['label' => 'Обзор',       'count' => null,        'disabled' => false],
            'thread'    => ['label' => 'Переписка',   'count' => $thread,     'disabled' => false],
            'items'     => ['label' => 'Позиции',     'count' => $items,      'disabled' => false],
            'suppliers' => ['label' => 'Поставщики',  'count' => null,        'disabled' => true],
            'activity'  => ['label' => 'Активность', 'count' => $activity,    'disabled' => false],
            'files'     => ['label' => 'Файлы',       'count' => $files,      'disabled' => false],
            'related'   => ['label' => 'Связанные',  'count' => null,        'disabled' => true],
        ];
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
