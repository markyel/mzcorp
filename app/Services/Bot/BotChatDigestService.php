<?php

namespace App\Services\Bot;

use App\Mail\BotChatDigestMail;
use App\Models\MessengerMessage;
use App\Models\User;
use App\Services\Mail\ManagerInboxCopyService;
use App\Services\Mail\SystemNotificationMailer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Письмо-сводка менеджеру о сообщениях клиентов в Telegram/MAX, которые он не
 * открыл за `delay_minutes` (по умолчанию 10). Решение заказчика 13.10.2026:
 * письмо на каждое сообщение — спам, колокольчик не смотрят. Поэтому:
 *  - повод для письма — непрочитанное сообщение, о котором ещё не писали и
 *    которое ждёт дольше задержки (успел открыть переписку — письма нет);
 *  - в письме все непрочитанные по всем заявкам менеджера сразу;
 *  - менеджеру не чаще раза в `min_interval_minutes` (15);
 *  - о каждом сообщении — одно письмо: уже упомянутое, но непрочитанное
 *    нового письма не вызывает (попадёт в следующее, если придёт новое).
 * Сразу — тост и уведомление ОС в mzCorp (BotChatSignalService).
 */
class BotChatDigestService
{
    public function __construct(
        private readonly SystemNotificationMailer $mailer,
        private readonly ManagerInboxCopyService $copies,
    ) {
    }

    /** @return int сколько писем отправлено */
    public function run(): int
    {
        $delay = max(1, (int) config('services.bot_chat_digest.delay_minutes', 10));
        $managerIds = MessengerMessage::query()
            ->join('requests', 'requests.id', '=', 'messenger_messages.request_id')
            ->where('messenger_messages.direction', 'in')
            ->where('messenger_messages.kind', MessengerMessage::KIND_CLIENT)
            ->whereNull('messenger_messages.read_at')
            ->whereNull('messenger_messages.emailed_at')
            ->where('messenger_messages.created_at', '<=', now()->subMinutes($delay))
            ->whereNotNull('requests.assigned_user_id')
            ->distinct()
            ->pluck('requests.assigned_user_id');

        $sent = 0;
        foreach (User::query()->whereIn('id', $managerIds)->get() as $manager) {
            $sent += $this->sendTo($manager) ? 1 : 0;
        }

        return $sent;
    }

    private function sendTo(User $manager): bool
    {
        $gapKey = 'bot:chat-digest:last:'.$manager->id;
        if (trim((string) $manager->email) === '' || Cache::has($gapKey)) {
            return false;
        }

        $messages = MessengerMessage::query()->unreadFor($manager->id)
            ->with(['request:id,internal_code,client_name,client_company', 'chat'])
            ->orderBy('messenger_messages.id')
            ->get();
        if ($messages->isEmpty()) {
            return false;
        }

        $threads = $messages->groupBy('request_id')->map(function ($group) {
            $line = BotChatSignalService::line($group->first());

            return [
                'request' => $line['request'],
                'url' => $line['url'],
                'channel' => $line['channel'],
                'client' => $line['client'],
                'messages' => $group->map(fn (MessengerMessage $m) => [
                    'at' => $m->created_at?->timezone('Europe/Moscow')->format('d.m H:i') ?? '',
                    'text' => BotChatSignalService::line($m)['text'],
                ])->values()->all(),
            ];
        })->values()->all();

        $mail = new BotChatDigestMail($threads);
        $messageId = 'botchat-'.$manager->id.'-'.now()->timestamp.'@mzcorp.ru';
        if (! $this->mailer->sendMailable((string) $manager->email, $mail, null, $messageId)) {
            Log::warning('BotChatDigest: not sent', ['manager_id' => $manager->id]);

            return false;
        }

        MessengerMessage::query()->whereIn('id', $messages->pluck('id'))->whereNull('emailed_at')->update(['emailed_at' => now()]);
        Cache::put($gapKey, 1, now()->addMinutes(max(1, (int) config('services.bot_chat_digest.min_interval_minutes', 15))));
        $this->copies->store($manager, $mail, $messageId, ['bot_chat_digest' => true]);

        return true;
    }
}
