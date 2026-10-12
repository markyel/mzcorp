<?php

namespace App\Livewire\Admin;

use App\Enums\Role;
use App\Models\MessengerChat;
use App\Models\MessengerMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * «Боты» — все диалоги ботов заявок Telegram/MAX для разбора их работы:
 * воронка (начали → подтвердили e-mail → смотрели цены → отправили заявку),
 * список клиентов с фильтрами и полная лента диалога — входящие, ответы бота,
 * нажатые кнопки, уведомления и ответы менеджеров.
 */
class BotChats extends Component
{
    public const PER_PAGE = 50;

    #[Url(as: 'ch', except: '')]
    public string $channel = '';

    #[Url(as: 'f', except: '')]
    public string $filter = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'days', except: 30)]
    public int $days = 30;

    #[Url(as: 'chat', except: null)]
    public ?int $chatId = null;

    public int $limit = self::PER_PAGE;

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    public function updated(string $field): void
    {
        if (in_array($field, ['channel', 'filter', 'search', 'days'], true)) {
            $this->limit = self::PER_PAGE;
        }
    }

    public function open(int $id): void
    {
        $this->chatId = $id;
    }

    public function more(): void
    {
        $this->limit += self::PER_PAGE;
    }

    public function toggleBlock(int $id): void
    {
        $this->authorizeAccess();
        $chat = MessengerChat::findOrFail($id);
        $chat->forceFill(['blocked_at' => $chat->blocked_at ? null : now()])->save();
    }

    /** Воронка за период: по клиентам, впервые написавшим боту в эти дни. */
    #[Computed]
    public function funnel(): array
    {
        $chats = MessengerChat::query()
            ->where('created_at', '>=', now()->subDays($this->days))
            ->when($this->channel !== '', fn ($q) => $q->where('channel', $this->channel));
        $ids = (clone $chats)->pluck('id');

        $priced = MessengerMessage::query()->whereIn('messenger_chat_id', $ids)
            ->where('direction', 'out')->where('kind', 'bot')->where('text', 'like', '%Наличие:%')
            ->distinct()->count('messenger_chat_id');

        return [
            'started' => $ids->count(),
            'verified' => (clone $chats)->whereNotNull('email_verified_at')->count(),
            'priced' => $priced,
            'requested' => (clone $chats)->where('requests_count', '>', 0)->count(),
            'requests' => (int) (clone $chats)->sum('requests_count'),
            'messages_in' => MessengerMessage::query()->whereIn('messenger_chat_id', $ids)->where('direction', 'in')->count(),
        ];
    }

    private function listQuery(): Builder
    {
        $q = MessengerChat::query()
            ->when($this->channel !== '', fn ($q) => $q->where('channel', $this->channel))
            ->when($this->filter === 'verified', fn ($q) => $q->whereNotNull('email_verified_at'))
            ->when($this->filter === 'unverified', fn ($q) => $q->whereNull('email_verified_at'))
            ->when($this->filter === 'with_requests', fn ($q) => $q->where('requests_count', '>', 0))
            ->when($this->filter === 'blocked', fn ($q) => $q->whereNotNull('blocked_at'));

        $s = trim($this->search);
        if ($s !== '') {
            $like = '%'.$s.'%';
            $q->where(fn ($w) => $w->where('email', 'ilike', $like)
                ->orWhere('username', 'ilike', $like)
                ->orWhere('first_name', 'ilike', $like)
                ->orWhere('last_name', 'ilike', $like)
                ->orWhere('contact_name', 'ilike', $like)
                ->orWhere('company_name', 'ilike', $like)
                ->orWhere('phone', 'ilike', $like)
                ->orWhere('inn', 'ilike', $like)
                ->orWhere('external_user_id', $s));
        }

        return $q;
    }

    #[Computed]
    public function total(): int
    {
        return $this->listQuery()->count();
    }

    /** @return Collection<int, MessengerChat> */
    #[Computed]
    public function chats(): Collection
    {
        return $this->listQuery()->with('organization:id,name')
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->limit($this->limit)->get();
    }

    #[Computed]
    public function selected(): ?MessengerChat
    {
        return $this->chatId ? MessengerChat::query()->with('organization:id,name')->find($this->chatId) : null;
    }

    /** @return Collection<int, MessengerMessage> последние 500 сообщений диалога */
    #[Computed]
    public function messages(): Collection
    {
        if ($this->selected === null) {
            return collect();
        }

        return MessengerMessage::query()
            ->where('messenger_chat_id', $this->selected->id)
            ->with(['author:id,name', 'request:id,internal_code'])
            ->orderByDesc('id')->limit(500)->get()->reverse()->values();
    }

    #[Computed]
    public function chatRequests(): Collection
    {
        return $this->selected
            ? $this->selected->requests()->orderByDesc('id')->get(['id', 'internal_code', 'status', 'created_at'])
            : collect();
    }

    private function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->hasAnyRole([Role::HeadOfSales->value, Role::Director->value, Role::Admin->value]), 403);
    }

    public function render()
    {
        return view('livewire.admin.bot-chats');
    }
}
