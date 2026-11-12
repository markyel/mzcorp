<?php

namespace App\Livewire\Bots;

use App\Models\MessengerChat;
use App\Models\MessengerMessage;
use App\Services\Bot\BotLiveChatService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Окно живого чата с клиентом бота (12.11.2026) — в общем макете, на любой странице сайта: менеджер взял
 * чат на себя (BotLiveChatService), переписывается в реальном времени, бот молчит. Окно плавающее,
 * перетаскивается, место — в localStorage: при переходе в другой раздел встаёт туда же. Новые сообщения —
 * опросом раз в 3 с. Вышла тишина или менеджер вернул чат — окно закрывается.
 */
class LiveChat extends Component
{
    public ?int $chatId = null;

    public string $text = '';

    public ?string $error = null;

    public function mount(): void
    {
        $this->chatId = app(BotLiveChatService::class)->currentFor(auth()->user())?->id;
    }

    /** Чат взят — из заявки или раздела «Боты» (событие страницы). */
    #[On('live-chat-opened')]
    public function opened(): void
    {
        $this->chatId = app(BotLiveChatService::class)->currentFor(auth()->user())?->id;
        $this->error = null;
        unset($this->chat, $this->messages);
    }

    #[Computed]
    public function chat(): ?MessengerChat
    {
        return $this->chatId ? MessengerChat::query()->with('takenBy:id,name')->find($this->chatId) : null;
    }

    /** @return Collection<int, MessengerMessage> */
    #[Computed]
    public function messages(): Collection
    {
        if ($this->chatId === null) {
            return collect();
        }

        return MessengerMessage::query()->where('messenger_chat_id', $this->chatId)->with('author:id,name')
            ->latest('id')->limit(40)->get()->reverse()->values();
    }

    /** Опрос: новые сообщения; чат вернулся боту (тишина, коллега из «Ботов») — окно закрывается. */
    public function tick(): void
    {
        $chat = $this->chat;
        if ($chat === null || (int) $chat->taken_by_user_id !== (int) auth()->id() || ! BotLiveChatService::isTaken($chat)) {
            if ($chat !== null && (int) $chat->taken_by_user_id === (int) auth()->id()) {
                app(BotLiveChatService::class)->release($chat, 'timeout');
            }
            $this->chatId = null;
            unset($this->chat, $this->messages);
            $this->dispatch('toast', type: 'info', message: 'Чат вернулся боту — дальше клиенту отвечает бот.');

            return;
        }
        unset($this->messages);
    }

    public function send(): void
    {
        $this->error = null;
        $this->validate(['text' => ['required', 'string', 'max:'.BotLiveChatService::MAX_TEXT]], [], ['text' => 'сообщение']);
        $chat = $this->chat;
        if ($chat === null) {
            return;
        }
        $res = app(BotLiveChatService::class)->send($chat, auth()->user(), $this->text);
        if ($res['ok']) {
            $this->text = '';
        } else {
            $this->error = $res['message'];
        }
        unset($this->chat, $this->messages);
    }

    /** «Вернуть боту». */
    public function release(): void
    {
        if (($chat = $this->chat) !== null && (int) $chat->taken_by_user_id === (int) auth()->id()) {
            app(BotLiveChatService::class)->release($chat, 'manual', auth()->user());
        }
        $this->chatId = null;
        unset($this->chat, $this->messages);
        $this->dispatch('live-chat-closed');
    }

    public function render()
    {
        return view('livewire.bots.live-chat');
    }
}
