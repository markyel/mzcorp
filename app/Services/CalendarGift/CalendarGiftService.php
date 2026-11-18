<?php

namespace App\Services\CalendarGift;

use App\Models\CalendarGiftRecipient;
use App\Models\MessengerMessage;
use App\Services\Bot\BotMessengers;
use Illuminate\Support\Facades\Log;

/**
 * Действия со списком получателей календаря: статус и сообщение получателю в бот — «календарь в пути», как обещали
 * при записи (CalendarGiftFlow::save).
 */
class CalendarGiftService
{
    public function __construct(private readonly BotMessengers $messengers) {}

    /** @return bool сообщение в бот ушло (false — статус сменён, написать не удалось) */
    public function setStatus(CalendarGiftRecipient $r, string $status): bool
    {
        if (! array_key_exists($status, CalendarGiftRecipient::STATUSES) || $r->status === $status) {
            return true;
        }
        $wasSent = $r->status === CalendarGiftRecipient::STATUS_SENT;
        $r->forceFill(['status' => $status, 'sent_at' => $status === CalendarGiftRecipient::STATUS_SENT ? now() : ($wasSent ? null : $r->sent_at)])->save();

        return $status === CalendarGiftRecipient::STATUS_SENT ? $this->tellSent($r) : true;
    }

    private function tellSent(CalendarGiftRecipient $r): bool
    {
        $chat = $r->chat;
        $messenger = $chat ? $this->messengers->for($r->channel) : null;
        if ($chat === null || $messenger === null || $chat->blocked_at !== null) {
            return false;
        }
        $text = 'Календарь MyZip '.$r->campaign.' отправлен 🎁 Ждите по адресу: '.e($r->address).'. Спасибо, что вы с нами!';
        try {
            $ok = $messenger->sendText((string) $chat->chat_id, $text);
        } catch (\Throwable $e) {
            Log::warning('CalendarGift: sent notice failed', ['recipient_id' => $r->id, 'error' => $e->getMessage()]);
            $ok = false;
        }
        MessengerMessage::create(['messenger_chat_id' => $chat->id, 'direction' => 'out', 'kind' => 'bot', 'text' => $text, 'meta' => $ok ? null : ['send_failed' => true]]);

        return $ok;
    }
}
