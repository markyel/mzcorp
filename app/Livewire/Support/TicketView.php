<?php

namespace App\Livewire\Support;

use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Services\Support\SupportTicketService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Детальная страница тикета — тред + форма ответа + (для админа)
 * управление статусом.
 *
 * Доступ: автор тикета или admin. Проверка на mount() и в каждой
 * write-операции.
 */
class TicketView extends Component
{
    use WithFileUploads;

    public int $ticketId;

    #[Validate('required|string|min:1|max:5000')]
    public string $reply = '';

    public bool $isInternal = false;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    #[Validate([
        'replyAttachments.*' => 'file|max:10240',
    ])]
    public array $replyAttachments = [];

    public function mount(SupportTicket $ticket): void
    {
        $this->authorize_($ticket);
        $this->ticketId = $ticket->id;

        // Пометить непрочитанные support_reply-нотификации этого тикета
        // как read для текущего пользователя — снимет цифру с badge ▲
        // в шапке и с маркера в bell. Для админа (он не получает
        // support_reply, только email) — no-op.
        $user = auth()->user();
        if ($user && $user->id === $ticket->user_id) {
            $user->notifications()
                ->where('type', \App\Notifications\SupportTicketReplyNotification::class)
                ->whereNull('read_at')
                ->whereRaw("(data::jsonb->>'ticket_id')::int = ?", [$ticket->id])
                ->update(['read_at' => now()]);
        }
    }

    #[Computed]
    public function ticket(): SupportTicket
    {
        return SupportTicket::with([
            'user',
            'assignee',
            'messages.author',
            'messages.attachments',
            'attachments' => fn ($q) => $q->whereNull('message_id'),
        ])->findOrFail($this->ticketId);
    }

    #[Computed]
    public function isAdmin(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    #[Computed]
    public function visibleMessages()
    {
        $admin = $this->isAdmin;
        return $this->ticket->messages
            ->filter(fn ($m) => $admin || ! $m->is_internal);
    }

    public function sendReply(SupportTicketService $service): void
    {
        $this->authorize_($this->ticket);
        $this->validate();

        // Только админ может оставлять internal-заметки.
        $internal = $this->isAdmin && $this->isInternal;

        $service->addReply(
            $this->ticket,
            auth()->user(),
            $this->reply,
            $internal,
            $this->replyAttachments,
        );

        $this->reset(['reply', 'replyAttachments', 'isInternal']);
        unset($this->ticket, $this->visibleMessages);
        session()->flash('support_status', 'Ответ отправлен.');
    }

    public function changeStatus(string $to, SupportTicketService $service): void
    {
        $this->authorize_($this->ticket, requireAdmin: true);

        $status = SupportTicketStatus::tryFrom($to);
        if (! $status) {
            return;
        }
        $service->changeStatus($this->ticket, $status, auth()->user());
        unset($this->ticket);
        session()->flash('support_status', 'Статус обновлён: ' . $status->label());
    }

    public function closeAsAuthor(SupportTicketService $service): void
    {
        $this->authorize_($this->ticket);
        // Автор может закрыть только свой тикет.
        if (auth()->id() !== $this->ticket->user_id) {
            return;
        }
        $service->changeStatus($this->ticket, SupportTicketStatus::Closed, auth()->user());
        unset($this->ticket);
        session()->flash('support_status', 'Тикет закрыт. Спасибо!');
    }

    private function authorize_(SupportTicket $ticket, bool $requireAdmin = false): void
    {
        $user = auth()->user();
        abort_unless($user, 403);
        if ($requireAdmin) {
            abort_unless($user->hasRole('admin'), 403);
            return;
        }
        $owns = $user->id === $ticket->user_id;
        $admin = $user->hasRole('admin');
        abort_unless($owns || $admin, 403);
    }

    public function render()
    {
        return view('livewire.support.ticket-view');
    }
}
