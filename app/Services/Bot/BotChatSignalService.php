<?php

namespace App\Services\Bot;

use App\Models\MessengerMessage;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Сигнал «клиент написал в Telegram/MAX» для опроса из любого раздела CRM —
 * рядом с сигналом новой почты (NewMailSignalController, mail-signal.js).
 *
 * Заказчик (13.10.2026): о сообщении в чате менеджер не узнаёт — в колокольчик
 * не заглядывают, письмо на каждое сообщение — спам. Поэтому сразу: тост и
 * уведомление ОС с текстом и ссылкой на окно переписки; а если менеджер так и
 * не открыл переписку — письмо-сводка (BotChatDigestService).
 */
class BotChatSignalService
{
    private const FRESH_LIMIT = 3;

    /**
     * @return array{unread: int, cursor: int, fresh: list<array{id:int, request:string, channel:string, client:string, text:string, url:string}>, fresh_count: int}
     */
    public function summary(User $user, ?int $afterId): array
    {
        $unread = MessengerMessage::query()->unreadFor($user->id);
        $count = (clone $unread)->count();
        // Курсор — по непрочитанным: прочитанное в другой вкладке всплывать не должно.
        $cursor = (int) (clone $unread)->max('messenger_messages.id');

        $fresh = [];
        $freshCount = 0;
        if ($afterId !== null && $cursor > $afterId) {
            $rows = (clone $unread)->where('messenger_messages.id', '>', $afterId)
                ->with(['request:id,internal_code,client_name,client_company', 'chat'])
                ->orderByDesc('messenger_messages.id')
                ->get();
            $freshCount = $rows->count();
            $fresh = $rows->take(self::FRESH_LIMIT)->map(fn (MessengerMessage $m) => self::line($m))->values()->all();
        }

        return ['unread' => $count, 'cursor' => $cursor, 'fresh' => $fresh, 'fresh_count' => $freshCount];
    }

    /**
     * Строка о сообщении — для тоста и письма-сводки.
     *
     * @return array{id:int, request:string, channel:string, client:string, text:string, url:string}
     */
    public static function line(MessengerMessage $m): array
    {
        $files = array_column((array) (($m->meta ?? [])['attachments'] ?? []), 'name');
        $text = trim((string) $m->text);
        if ($text === '' && $files !== []) {
            $text = '📎 '.implode(', ', $files);
        }

        $request = $m->request;
        $client = trim((string) ($request?->client_name ?: $request?->client_company))
            ?: ($m->chat?->displayName() ?? 'Клиент');

        return [
            'id' => (int) $m->id,
            'request' => (string) ($request?->internal_code ?? ''),
            'channel' => $m->chat?->channel?->label() ?? 'мессенджер',
            'client' => $client,
            'text' => Str::limit($text, 300),
            'url' => $request ? route('requests.show', ['request' => $request->id, 'chat' => 1]) : route('requests.index'),
        ];
    }
}
