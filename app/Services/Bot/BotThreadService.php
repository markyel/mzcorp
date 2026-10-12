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
use Illuminate\Support\Facades\Storage;

/**
 * Переписка по заявке в мессенджере (Telegram/MAX): клиент пишет менеджеру из
 * бота, менеджер отвечает из окна переписки в карточке заявки. Решение
 * заказчика: если клиент пришёл из мессенджера, уточнения — там же, а не
 * письмом. Сообщения — messenger_messages с request_id; файлы — в meta.attachments
 * (диск local, отдаёт MessengerFileController).
 */
class BotThreadService
{
    /** Файлов в одном сообщении менеджера и размер одного (лимит Telegram на отправку — 50 МБ). */
    public const MAX_FILES = 10;

    public const MAX_FILE_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly BotMessengers $messengers,
        private readonly RequestActivityService $activity,
        private readonly BotIntakeStore $store,
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

    /** @param  list<array{path: string, name: string, mime: ?string, size: int}>  $files сохранённые файлы клиента */
    public function fromClient(MessengerChat $chat, Request $request, string $text, array $files = []): void
    {
        MessengerMessage::create([
            'messenger_chat_id' => $chat->id,
            'request_id' => $request->id,
            'direction' => 'in',
            'kind' => 'client',
            'text' => mb_substr($text, 0, 4000),
            'meta' => $files !== [] ? ['attachments' => array_values($files)] : null,
        ]);
        $this->activity->touch($request, RequestActivityType::ClientReplied);

        $manager = $request->assigned_user_id ? User::find($request->assigned_user_id) : null;
        if ($manager !== null) {
            $preview = trim($text) !== '' ? $text : '📎 '.implode(', ', array_column($files, 'name'));
            try {
                $manager->notify(new BotClientMessageNotification($request, $chat->channel->label(), $preview));
            } catch (\Throwable $e) {
                Log::warning('BotThreadService: manager notify failed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Сообщение менеджера клиенту в мессенджер: текст и/или файлы.
     *
     * @param  list<array{bytes: string, name: string, mime: ?string}>  $uploads
     * @return array{ok: bool, message: string}
     */
    public function fromManager(Request $request, User $manager, string $text, array $uploads = []): array
    {
        $text = trim($text);
        $chat = $request->messengerChat;
        if ($chat === null || ($text === '' && $uploads === [])) {
            return ['ok' => false, 'message' => 'Нечего отправлять.'];
        }
        if ($chat->blocked_at !== null) {
            return ['ok' => false, 'message' => 'Клиент заблокирован в боте (раздел «Боты»).'];
        }
        $messenger = $this->messengers->for($chat->channel);
        if ($messenger === null) {
            return ['ok' => false, 'message' => 'Бот '.$chat->channel->label().' не подключён.'];
        }

        $head = '💬 <b>'.e($manager->name).'</b>, MyZip · заявка <b>'.e((string) $request->internal_code).'</b>';
        $buttons = [[['text' => '↩️ Ответить', 'data' => 'reply:'.$request->id]]];
        $ok = true;
        if ($text !== '') {
            $ok = $messenger->sendText((string) $chat->chat_id, $head."\n".e($text), $uploads === [] ? $buttons : []);
        }

        $stored = [];
        foreach (array_slice($uploads, 0, self::MAX_FILES) as $i => $u) {
            if (! $ok) {
                break;
            }
            $caption = $text === '' && $i === 0 ? $head : null;
            $ok = $messenger->sendDocument((string) $chat->chat_id, $u['bytes'], $u['name'], $caption);
            $stored[] = $this->store->storeDraftFile($chat, $u['bytes'], $u['name'], $u['mime'], 'bot-thread');
        }
        // Кнопка «Ответить» — под последним, если слали файлы.
        if ($ok && $uploads !== []) {
            $messenger->sendText((string) $chat->chat_id, 'Ответить менеджеру по заявке <b>'.e((string) $request->internal_code).'</b>:', $buttons);
        }

        $blocked = ! $ok && $messenger->lastSendBlocked();
        MessengerMessage::create([
            'messenger_chat_id' => $chat->id,
            'request_id' => $request->id,
            'user_id' => $manager->id,
            'direction' => 'out',
            'kind' => 'manager',
            'text' => $text,
            'meta' => array_filter([
                'attachments' => $stored !== [] ? $stored : null,
                'send_failed' => $ok ? null : true,
                'bot_blocked' => $blocked ?: null,
            ]),
        ]);
        if ($blocked) {
            $chat->stateSet('bot_blocked_at', now()->toIso8601String());
            $chat->save();

            return ['ok' => false, 'message' => 'Клиент отключил бота в '.$chat->channel->label().' — сообщение не доставлено. Напишите ему на '.$request->client_email.'.'];
        }
        if (! $ok) {
            return ['ok' => false, 'message' => $chat->channel->label().' не принял сообщение — попробуйте ещё раз.'];
        }
        $chat->stateSet('active_request_id', $request->id);
        $chat->save();
        $this->activity->touch($request, RequestActivityType::ManagerReplied);

        return ['ok' => true, 'message' => 'Отправлено в '.$chat->channel->label().'.'];
    }

    /** Статус клиента для окна переписки: когда был в боте, не отключил ли бота. */
    public static function presence(MessengerChat $chat): array
    {
        $last = MessengerMessage::query()->where('messenger_chat_id', $chat->id)->where('direction', 'in')->max('created_at');
        $last = $last ? \Illuminate\Support\Carbon::parse($last) : null;
        $blocked = $chat->stateGet('bot_blocked_at');
        // Клиент написал после отказа — снова на связи.
        if ($blocked && $last && $last->gt(\Illuminate\Support\Carbon::parse($blocked))) {
            $blocked = null;
        }

        return [
            'last' => $last,
            'active' => $last !== null && $last->gt(now()->subMinutes(5)),
            'blocked' => $blocked !== null,
        ];
    }

    /** Файл вложения сообщения переписки (для скачивания). */
    public static function attachment(MessengerMessage $message, int $index): ?array
    {
        $att = (array) ((($message->meta ?? [])['attachments'] ?? [])[$index] ?? []);
        if (empty($att['path']) || ! Storage::disk(BotIntakeStore::DISK)->exists($att['path'])) {
            return null;
        }

        return $att;
    }
}
