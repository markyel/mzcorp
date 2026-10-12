<?php

namespace App\Livewire\Requests;

use App\Models\MessengerMessage;
use App\Models\Request;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Карточка заявки из бота → «Переписка»: лента сообщений клиента в
 * Telegram/MAX, уведомлений бота и ответов менеджеров. Писать клиенту — в
 * окне переписки (MessengerDialog), кнопка внизу блока.
 */
class MessengerThread extends Component
{
    public int $requestId;

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

    public function render()
    {
        return view('livewire.requests.messenger-thread');
    }
}
