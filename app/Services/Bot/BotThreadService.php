<?php

namespace App\Services\Bot;

use App\Enums\RequestActivityType;
use App\Models\MessengerChat;
use App\Models\MessengerMessage;
use App\Models\Request;
use App\Models\User;
use App\Notifications\BotClientMessageNotification;
use App\Services\Request\RequestActivityService;
use Illuminate\Support\Facades\Log;

/**
 * Переписка по заявке в боте (этап 2): клиент пишет менеджеру из мессенджера,
 * менеджер отвечает из карточки заявки. Сообщения — messenger_messages с
 * request_id; карточка показывает их рядом с письмами.
 */
class BotThreadService
{
    public function __construct(
        private readonly BotMessengers $messengers,
        private readonly RequestActivityService $activity,
    ) {}

    /** Открытые заявки клиента — по ним можно написать менеджеру. */
    public static function openRequests(MessengerChat $chat): \Illuminate\Support\Collection
    {
        return Request::query()
            ->where('messenger_chat_id', $chat->id)
            ->whereNull('closed_at')
            ->where('created_at', '>=', now()->subDays(90))
            ->latest('id')
            ->limit(5)
            ->get(['id', 'internal_code', 'assigned_user_id', 'client_name', 'status']);
    }

    public function fromClient(MessengerChat $chat, Request $request, string $text): void
    {
        MessengerMessage::create([
            'messenger_chat_id' => $chat->id,
            'request_id' => $request->id,
            'direction' => 'in',
            'kind' => 'client',
            'text' => mb_substr($text, 0, 4000),
        ]);
        $this->activity->touch($request, RequestActivityType::ClientReplied);

        $manager = $request->assigned_user_id ? User::find($request->assigned_user_id) : null;
        if ($manager !== null) {
            try {
                $manager->notify(new BotClientMessageNotification($request, $chat->channel->label(), $text));
            } catch (\Throwable $e) {
                Log::warning('BotThreadService: manager notify failed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /** @return array{ok: bool, message: string} */
    public function fromManager(Request $request, User $manager, string $text): array
    {
        $text = trim($text);
        $chat = $request->messengerChat;
        if ($chat === null || $text === '') {
            return ['ok' => false, 'message' => 'Нечего отправлять.'];
        }
        if ($chat->blocked_at !== null) {
            return ['ok' => false, 'message' => 'Клиент заблокирован в боте.'];
        }
        $messenger = $this->messengers->for($chat->channel);
        if ($messenger === null) {
            return ['ok' => false, 'message' => 'Бот '.$chat->channel->label().' не подключён.'];
        }

        $html = '💬 <b>'.e($manager->name).'</b>, MyZip · заявка <b>'.e((string) $request->internal_code)."</b>\n".e($text);
        $ok = $messenger->sendText((string) $chat->chat_id, $html, [[['text' => '↩️ Ответить', 'data' => 'reply:'.$request->id]]]);

        MessengerMessage::create([
            'messenger_chat_id' => $chat->id,
            'request_id' => $request->id,
            'user_id' => $manager->id,
            'direction' => 'out',
            'kind' => 'manager',
            'text' => $text,
            'meta' => $ok ? null : ['send_failed' => true],
        ]);
        if (! $ok) {
            return ['ok' => false, 'message' => $chat->channel->label().' не принял сообщение — попробуйте ещё раз.'];
        }
        $chat->stateSet('active_request_id', $request->id);
        $chat->save();
        $this->activity->touch($request, RequestActivityType::ManagerReplied);

        return ['ok' => true, 'message' => 'Отправлено в '.$chat->channel->label().'.'];
    }
}
