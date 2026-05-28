<?php

namespace App\Livewire\Support;

use App\Models\SupportTicket;
use App\Services\Support\SupportTicketService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Глобальная модалка «связь с создателем». Висит в layouts.navigation
 * рядом с bell. Открывается через Livewire-событие
 * `open-support-modal` с payload контекста (url, route, viewport, user_agent),
 * который собирается JS-обвязкой на момент клика.
 *
 * Имеет два режима:
 *   - mode='new'  — форма создания нового тикета (default при открытии).
 *   - mode='view' — inline-тред конкретного тикета: история сообщений
 *                   + форма ответа (текст + файлы). Без перехода на
 *                   /support/{ticket} — пользователь не теряет контекст.
 *
 * Переключение: клик на тикет в блоке «Мои обращения» / по кнопке
 * «Открыть тикет» в success-state → `viewTicket($id)`. Возврат —
 * `backToList()` (стрелка «← к новому обращению»).
 */
class NewTicketModal extends Component
{
    use WithFileUploads;

    public bool $open = false;

    /** 'new' | 'view' */
    public string $mode = 'new';

    /** Id просматриваемого тикета при mode=view. */
    public ?int $viewTicketId = null;

    // ── Поля формы НОВОГО тикета ─────────────────────────────────────────

    #[Validate('nullable|string|max:200')]
    public string $subject = '';

    #[Validate('required|string|min:5|max:5000')]
    public string $body = '';

    /**
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    #[Validate([
        'attachments.*' => 'file|max:10240', // 10 МБ × файл
    ])]
    public array $attachments = [];

    /** @var array<string, mixed> */
    public array $context = [];

    public bool $sentSuccess = false;
    public ?int $sentTicketId = null;

    // ── Поля формы ОТВЕТА в существующий тикет (mode=view) ──────────────

    #[Validate('required|string|min:1|max:5000')]
    public string $reply = '';

    /**
     * @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    #[Validate([
        'replyAttachments.*' => 'file|max:10240',
    ])]
    public array $replyAttachments = [];

    public ?string $replyFlash = null;

    #[On('open-support-modal')]
    public function show(array $context = []): void
    {
        $this->reset(['subject', 'body', 'attachments', 'sentSuccess', 'sentTicketId',
                      'reply', 'replyAttachments', 'replyFlash', 'viewTicketId']);
        $this->mode = 'new';
        $this->resetErrorBag();
        $this->context = $this->sanitizeContext($context);
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function save(SupportTicketService $service): void
    {
        $this->validate([
            'subject' => 'nullable|string|max:200',
            'body' => 'required|string|min:5|max:5000',
            'attachments.*' => 'file|max:10240',
        ]);

        $ticket = $service->createTicket(
            auth()->user(),
            [
                'subject' => $this->subject,
                'body' => $this->body,
                'context' => $this->context,
            ],
            $this->attachments,
        );

        $this->sentTicketId = $ticket->id;
        $this->sentSuccess = true;
        $this->reset(['subject', 'body', 'attachments']);
    }

    /**
     * Переключиться в режим просмотра конкретного тикета (inline-тред).
     * Авторизация: автор тикета или admin.
     */
    public function viewTicket(int $ticketId): void
    {
        $ticket = SupportTicket::find($ticketId);
        if (! $ticket) {
            $this->replyFlash = 'Тикет не найден.';
            return;
        }
        $user = auth()->user();
        $owns = $user && $user->id === $ticket->user_id;
        $admin = $user && $user->hasRole('admin');
        if (! ($owns || $admin)) {
            $this->replyFlash = 'Нет доступа к этому тикету.';
            return;
        }

        $this->viewTicketId = $ticket->id;
        $this->mode = 'view';
        $this->reset(['reply', 'replyAttachments']);
        $this->replyFlash = null;
        $this->resetErrorBag();

        // Снять unread support_reply-нотификации этого тикета.
        if ($owns) {
            $user->notifications()
                ->where('type', \App\Notifications\SupportTicketReplyNotification::class)
                ->whereNull('read_at')
                ->whereRaw("(data::jsonb->>'ticket_id')::int = ?", [$ticket->id])
                ->update(['read_at' => now()]);
        }
    }

    public function backToList(): void
    {
        $this->mode = 'new';
        $this->viewTicketId = null;
        $this->sentSuccess = false;
        $this->sentTicketId = null;
        $this->reset(['reply', 'replyAttachments']);
        $this->replyFlash = null;
        $this->resetErrorBag();
        // Сбрасываем флаг success — при возврате видим чистую форму нового тикета.
    }

    /**
     * Отправить ответ в текущий просматриваемый тикет (mode=view).
     */
    public function sendReply(SupportTicketService $service): void
    {
        if ($this->mode !== 'view' || $this->viewTicketId === null) {
            return;
        }

        $ticket = SupportTicket::find($this->viewTicketId);
        if (! $ticket) {
            $this->replyFlash = 'Тикет недоступен.';
            return;
        }
        $user = auth()->user();
        $owns = $user && $user->id === $ticket->user_id;
        $admin = $user && $user->hasRole('admin');
        if (! ($owns || $admin)) {
            $this->replyFlash = 'Нет доступа.';
            return;
        }

        $this->validate([
            'reply' => 'required|string|min:1|max:5000',
            'replyAttachments.*' => 'file|max:10240',
        ]);

        // isInternal=false — автор в принципе не может оставлять internal,
        // и сама модалка под админа сейчас не заточена (для админа есть
        // отдельная страница /support/{ticket} с isInternal-чекбоксом).
        $service->addReply(
            $ticket,
            $user,
            $this->reply,
            false,
            $this->replyAttachments,
        );

        $this->reset(['reply', 'replyAttachments']);
        $this->resetErrorBag();
        $this->replyFlash = 'Комментарий добавлен.';
        unset($this->viewedTicket);
    }

    /**
     * Загруженный тикет с тредом и вложениями для blade-рендера.
     */
    #[Computed]
    public function viewedTicket(): ?SupportTicket
    {
        if ($this->viewTicketId === null) {
            return null;
        }
        return SupportTicket::with([
            'user',
            'messages.author',
            'messages.attachments',
            'attachments' => fn ($q) => $q->whereNull('message_id'),
        ])->find($this->viewTicketId);
    }

    /**
     * Не доверяем браузеру вслепую — режем длины, выбрасываем неожиданные ключи.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $raw): array
    {
        return [
            'url' => isset($raw['url']) ? mb_substr((string) $raw['url'], 0, 500) : null,
            'route_name' => isset($raw['route_name']) ? mb_substr((string) $raw['route_name'], 0, 120) : null,
            'viewport' => isset($raw['viewport']) ? mb_substr((string) $raw['viewport'], 0, 32) : null,
            'user_agent' => isset($raw['user_agent']) ? mb_substr((string) $raw['user_agent'], 0, 500) : null,
            'referrer' => isset($raw['referrer']) ? mb_substr((string) $raw['referrer'], 0, 500) : null,
        ];
    }

    /**
     * Последние 5 обращений текущего пользователя — для блока «Мои обращения»
     * наверху модалки. С маркером непрочитанного ответа админа (через
     * notifications-таблицу: kind=support_reply без read_at).
     */
    #[Computed]
    public function recentTickets()
    {
        $user = auth()->user();
        if (! $user) {
            return collect();
        }
        $tickets = SupportTicket::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get();
        if ($tickets->isEmpty()) {
            return collect();
        }

        // Считаем unread support_reply notifications per ticket.
        $unreadByTicket = $user->notifications()
            ->where('type', \App\Notifications\SupportTicketReplyNotification::class)
            ->whereNull('read_at')
            ->get()
            ->groupBy(fn ($n) => (int) ($n->data['ticket_id'] ?? 0))
            ->map->count();

        return $tickets->map(function ($t) use ($unreadByTicket) {
            return (object) [
                'id' => $t->id,
                'subject' => $t->subject,
                'status' => $t->status,
                'created_at' => $t->created_at,
                'unread' => (int) ($unreadByTicket[$t->id] ?? 0),
            ];
        });
    }

    public function render()
    {
        return view('livewire.support.new-ticket-modal');
    }
}
