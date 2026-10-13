<?php

namespace App\Livewire\Requests;

use App\Models\MessengerMessage;
use App\Models\Request;
use App\Services\Bot\BotThreadService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Окно переписки с клиентом в Telegram/MAX поверх карточки заявки — как окно
 * письма: перетаскивается, растягивается, сворачивается. Решение заказчика:
 * клиент пришёл из мессенджера — уточнения там же, а не письмом. Видна вся
 * переписка по заявке, отправка текста и файлов прямо в мессенджер.
 */
class MessengerDialog extends Component
{
    use WithFileUploads;

    public int $requestId;

    public bool $open = false;

    public string $text = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    public ?string $error = null;

    public ?string $flash = null;

    /** Ссылка из уведомления «Клиент написал…» (?chat=1) открывает окно сразу. */
    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
        if (request()->query('chat')) {
            $this->open = true;
            $this->markRead();
        }
    }

    #[On('open-messenger')]
    public function show(?int $requestId = null): void
    {
        if ($requestId !== null && $requestId !== $this->requestId) {
            return;
        }
        $this->open = true;
        $this->error = $this->flash = null;
        $this->markRead();
        unset($this->messages);
    }

    public function close(): void
    {
        $this->open = false;
    }

    /** Опрос каждые 8 с: при открытом окне — подтянуть новые сообщения клиента. */
    public function refreshIfOpen(): void
    {
        if (! $this->open) {
            $this->skipRender();

            return;
        }
        $this->markRead();
        unset($this->messages, $this->presence);
    }

    /**
     * Ответственный менеджер видит переписку — сообщения клиента прочитаны:
     * сигнал и письмо-сводка (bot:chat-digest) о них больше не нужны. РОП или
     * коллега, заглянувшие в окно, отметку не снимают — менеджер не увидел.
     */
    private function markRead(): void
    {
        if ((int) $this->request->assigned_user_id === (int) auth()->id()) {
            MessengerMessage::markReadForRequest($this->requestId);
        }
    }

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

    #[Computed]
    public function presence(): ?array
    {
        $chat = $this->request->messengerChat;

        return $chat ? BotThreadService::presence($chat) : null;
    }

    public function canWrite(): bool
    {
        return $this->request->isAccessibleBy(auth()->user());
    }

    public function removeUpload(int $i): void
    {
        unset($this->uploads[$i]);
        $this->uploads = array_values($this->uploads);
    }

    public function send(): void
    {
        $this->error = $this->flash = null;
        abort_unless($this->canWrite(), 403);
        $this->validate([
            'text' => ['nullable', 'string', 'max:3500'],
            'uploads' => ['array', 'max:'.BotThreadService::MAX_FILES],
            'uploads.*' => ['file', 'max:'.(BotThreadService::MAX_FILE_BYTES / 1024)],
        ], [
            'uploads.max' => 'Не больше '.BotThreadService::MAX_FILES.' файлов за раз.',
            'uploads.*.max' => 'Файл больше 20 МБ — отправьте его письмом.',
            'text.max' => 'Слишком длинное сообщение — до 3500 знаков.',
        ]);
        if (trim($this->text) === '' && $this->uploads === []) {
            return;
        }

        $files = array_map(fn ($u) => [
            'bytes' => (string) file_get_contents($u->getRealPath()),
            'name' => $u->getClientOriginalName(),
            'mime' => $u->getMimeType(),
        ], $this->uploads);

        $res = app(BotThreadService::class)->fromManager($this->request, auth()->user(), $this->text, $files);
        if ($res['ok']) {
            // Ответил — значит прочитал (даже если отвечает коллега за менеджера).
            MessengerMessage::markReadForRequest($this->requestId);
            $this->text = '';
            $this->uploads = [];
            $this->flash = $res['message'];
        } else {
            $this->error = $res['message'];
        }
        unset($this->messages, $this->presence);
        $this->dispatch('messenger-sent');
    }

    public function render()
    {
        return view('livewire.requests.messenger-dialog');
    }
}
