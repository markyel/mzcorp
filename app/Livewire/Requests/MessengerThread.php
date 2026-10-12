<?php

namespace App\Livewire\Requests;

use App\Models\MessengerMessage;
use App\Models\Request;
use App\Services\Bot\BotThreadService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Карточка заявки из бота → «Переписка»: сообщения клиента в Telegram/MAX,
 * уведомления бота и ответы менеджеров; ответ отсюда уходит в мессенджер.
 */
class MessengerThread extends Component
{
    public int $requestId;

    public string $reply = '';

    public ?string $flash = null;

    public ?string $error = null;

    #[Computed]
    public function request(): Request
    {
        $request = Request::query()->with('messengerChat')->findOrFail($this->requestId);
        abort_unless($request->isViewableBy(auth()->user()), 403);

        return $request;
    }

    /** @return Collection<int, MessengerMessage> */
    #[Computed]
    public function messages(): Collection
    {
        return MessengerMessage::query()
            ->where('request_id', $this->requestId)
            ->with('author:id,name')
            ->orderBy('id')
            ->get();
    }

    public function canReply(): bool
    {
        return $this->request->isAccessibleBy(auth()->user());
    }

    public function send(): void
    {
        $this->flash = $this->error = null;
        abort_unless($this->canReply(), 403);
        $text = trim($this->reply);
        if ($text === '') {
            return;
        }
        if (mb_strlen($text) > 3500) {
            $this->error = 'Слишком длинное сообщение — до 3500 знаков. Длинное лучше отправить письмом.';

            return;
        }

        $res = app(BotThreadService::class)->fromManager($this->request, auth()->user(), $text);
        if ($res['ok']) {
            $this->reply = '';
            $this->flash = $res['message'];
        } else {
            $this->error = $res['message'];
        }
        unset($this->messages);
    }

    public function render()
    {
        return view('livewire.requests.messenger-thread');
    }
}
