<?php

namespace App\Services\Bot;

use App\Models\MessengerChat;
use App\Models\MessengerMessage;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Живой чат с клиентом бота (12.11.2026). Переписка «по заявке» через бота медленная: клиент жмёт
 * «Ответить», ждёт… Здесь менеджер берёт чат на себя и пишет клиенту напрямую в окне поверх сайта;
 * пока чат за менеджером, бот на сообщения клиента не реагирует. Через IDLE_MINUTES тишины (ни менеджер,
 * ни клиент ничего не писали) или по кнопке «Вернуть боту» чат снова ведёт бот.
 *
 * Отдельно — «Написать от имени бота» из раздела «Боты»: одно сообщение без захвата чата.
 *
 * Сообщения менеджера в живом чате — kind «live» (с user_id), события начала/конца — kind «live» с
 * meta.event; от имени бота — kind «bot» с user_id. request_id — заявка, из которой взяли чат (её
 * переписка в карточке видит этот разговор).
 */
class BotLiveChatService
{
    /** Ни менеджер, ни клиент не писали столько минут — чат возвращается боту (решение заказчика 12.11.2026). */
    public const IDLE_MINUTES = 5;

    public const MAX_TEXT = 3500;

    public function __construct(private readonly BotMessengers $messengers) {}

    /** Чат сейчас ведёт менеджер (и тишина ещё не вышла). */
    public static function isTaken(MessengerChat $chat): bool
    {
        return $chat->taken_by_user_id !== null && $chat->taken_activity_at !== null
            && $chat->taken_activity_at->gt(now()->subMinutes(self::IDLE_MINUTES));
    }

    /** Когда чат вернётся боту, если никто ничего не напишет. */
    public static function releasesAt(MessengerChat $chat): ?\Illuminate\Support\Carbon
    {
        return $chat->taken_activity_at?->copy()->addMinutes(self::IDLE_MINUTES);
    }

    /** Чат, который сейчас ведёт этот менеджер (один на человека). */
    public function currentFor(User $user): ?MessengerChat
    {
        $chat = MessengerChat::query()->where('taken_by_user_id', $user->id)->latest('taken_at')->first();
        if ($chat === null) {
            return null;
        }
        if (! self::isTaken($chat)) {
            $this->release($chat, 'timeout');

            return null;
        }

        return $chat;
    }

    /**
     * Взять чат на себя: клиенту — что на связи менеджер, бот замолкает.
     *
     * @return array{ok: bool, message: string}
     */
    public function take(MessengerChat $chat, User $by, ?Request $request = null): array
    {
        if ($chat->blocked_at !== null) {
            return ['ok' => false, 'message' => 'Чат заблокирован — писать в него нельзя.'];
        }
        if ($chat->stateGet('bot_blocked_at')) {
            return ['ok' => false, 'message' => 'Клиент отключил бота — сообщения до него не доходят.'];
        }
        if (self::isTaken($chat)) {
            return (int) $chat->taken_by_user_id === (int) $by->id
                ? ['ok' => true, 'message' => 'Чат уже ваш.']
                : ['ok' => false, 'message' => 'Чат ведёт '.($chat->takenBy?->name ?? 'коллега').' — дождитесь, пока он вернёт его боту.'];
        }
        $mine = $this->currentFor($by);
        if ($mine !== null && $mine->id !== $chat->id) {
            return ['ok' => false, 'message' => 'У вас уже открыт чат с '.$mine->displayName().' — сначала верните его боту.'];
        }

        DB::transaction(function () use ($chat, $by, $request) {
            $chat->forceFill(['taken_by_user_id' => $by->id, 'taken_at' => now(), 'taken_activity_at' => now()]);
            $chat->stateSet('live_request_id', $request?->id);
            $chat->save();
        });
        $first = trim((string) strtok((string) $by->name, ' ')) ?: 'менеджер';
        $this->deliver($chat, $by, '👋 На связи '.e($first).', менеджер MyZip. Пишите — отвечу прямо здесь.', 'live', ['event' => 'start']);
        Log::info('Bot chat taken by a manager', ['chat_id' => $chat->id, 'user_id' => $by->id, 'request_id' => $request?->id]);

        return ['ok' => true, 'message' => 'Чат ваш: бот молчит, пока вы ведёте разговор ('.self::IDLE_MINUTES.' мин тишины — вернётся боту).'];
    }

    /** Вернуть чат боту: по кнопке (manual) или после тишины (timeout). Клиенту — что разговор закончен. */
    public function release(MessengerChat $chat, string $why = 'manual', ?User $by = null): void
    {
        if ($chat->taken_by_user_id === null) {
            return;
        }
        $manager = $chat->takenBy;
        $this->deliver($chat, $manager, 'Менеджер завершил разговор. Если что-то понадобится — нажмите /menu.', 'live',
            ['event' => 'end', 'why' => $why, 'by' => $by?->id]);
        $chat->forceFill(['taken_by_user_id' => null, 'taken_at' => null, 'taken_activity_at' => null]);
        $chat->stateForget('live_request_id');
        $chat->save();
        Log::info('Bot chat returned to the bot', ['chat_id' => $chat->id, 'why' => $why, 'manager_id' => $manager?->id, 'by' => $by?->id]);
    }

    /** @return array{ok: bool, message: string} */
    public function send(MessengerChat $chat, User $by, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'message' => 'Напишите сообщение.'];
        }
        if (! self::isTaken($chat) || (int) $chat->taken_by_user_id !== (int) $by->id) {
            return ['ok' => false, 'message' => 'Чат уже вернулся боту — возьмите его снова.'];
        }
        $ok = $this->deliver($chat, $by, nl2br(e(mb_substr($text, 0, self::MAX_TEXT)), false), 'live', null, $text);
        $chat->forceFill(['taken_activity_at' => now()])->save();

        return $ok ? ['ok' => true, 'message' => ''] : ['ok' => false, 'message' => 'Мессенджер не принял сообщение — попробуйте ещё раз.'];
    }

    /**
     * Одно сообщение клиенту от имени бота, без захвата чата (раздел «Боты»).
     *
     * @return array{ok: bool, message: string}
     */
    public function sendAsBot(MessengerChat $chat, User $by, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'message' => 'Напишите сообщение.'];
        }
        if ($chat->blocked_at !== null) {
            return ['ok' => false, 'message' => 'Чат заблокирован — писать в него нельзя.'];
        }
        $ok = $this->deliver($chat, $by, nl2br(e(mb_substr($text, 0, self::MAX_TEXT)), false), 'bot', ['staff' => true], $text);

        return $ok ? ['ok' => true, 'message' => 'Отправлено от имени бота.'] : ['ok' => false, 'message' => 'Мессенджер не принял сообщение.'];
    }

    /** Клиент написал, пока чат за менеджером: его сообщение — к заявке разговора, тишина считается заново. */
    public function clientWrote(MessengerChat $chat, MessengerMessage $message): void
    {
        if (($rid = $chat->stateGet('live_request_id')) !== null) {
            $message->forceFill(['request_id' => (int) $rid])->save();
        }
        $chat->forceFill(['taken_activity_at' => now()])->save();
    }

    /** Чаты, где тишина вышла, — боту (по расписанию раз в минуту). @return int сколько вернули */
    public function releaseIdle(): int
    {
        $n = 0;
        MessengerChat::query()->whereNotNull('taken_by_user_id')
            ->where(fn ($q) => $q->whereNull('taken_activity_at')->orWhere('taken_activity_at', '<=', now()->subMinutes(self::IDLE_MINUTES)))
            ->each(function (MessengerChat $chat) use (&$n) {
                $this->release($chat, 'timeout');
                $n++;
            });

        return $n;
    }

    /** Отправить в мессенджер и записать в журнал переписки. */
    private function deliver(MessengerChat $chat, ?User $by, string $html, string $kind, ?array $meta = null, ?string $plain = null): bool
    {
        $messenger = $this->messengers->for($chat->channel);
        $ok = $messenger?->sendText((string) $chat->chat_id, $html) ?? false;
        $rid = $chat->stateGet('live_request_id');
        MessengerMessage::create([
            'messenger_chat_id' => $chat->id,
            'request_id' => $rid !== null ? (int) $rid : null,
            'user_id' => $by?->id,
            'direction' => 'out',
            'kind' => $kind,
            'text' => $plain ?? $html,
            'meta' => array_filter(($meta ?? []) + ($ok ? [] : ['send_failed' => true])) ?: null,
        ]);
        if ($ok) {
            $chat->forceFill(['last_message_at' => now()])->save();
        }

        return $ok;
    }
}
