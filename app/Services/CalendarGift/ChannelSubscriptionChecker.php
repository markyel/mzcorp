<?php

namespace App\Services\CalendarGift;

use App\Enums\MessengerChannel;
use App\Models\MediaChannel;
use App\Services\Marketing\MediaPublisherService;
use App\Support\TelegramHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Подписан ли человек на наш канал. Спрашивает бот канала (медиаплан): только администратор канала видит его
 * участников, а бот заявок им не является. Id пользователя у ботов один — тот, что пришёл в бот заявок.
 *
 *   Telegram: getChatMember(@канал, user_id) — creator / administrator / member, restricted с is_member;
 *   MAX: GET /chats/{канал}/members?user_ids=… — пользователь среди участников.
 */
class ChannelSubscriptionChecker
{
    private const TELEGRAM_API = 'https://api.telegram.org/bot';

    /** true — подписан, false — нет, null — проверить не удалось (сеть, нет бота канала). */
    public function isSubscribed(MessengerChannel $channel, string $userId): ?bool
    {
        $media = $this->channel($channel);
        $token = $media?->secret('bot_token');
        $chatId = $media?->secret('chat_id');
        if ($media === null || ! $token || ! $chatId || trim($userId) === '') {
            return null;
        }

        try {
            return $channel === MessengerChannel::Telegram
                ? $this->telegram($token, $chatId, $userId)
                : $this->max($token, $chatId, $userId);
        } catch (\Throwable $e) {
            Log::warning('CalendarGift: subscription check failed', ['channel' => $channel->value, 'error' => MediaPublisherService::redactSecrets($e->getMessage())]);

            return null;
        }
    }

    /** Ссылка на канал для кнопки «Подписаться». */
    public function channelUrl(MessengerChannel $channel): ?string
    {
        $url = trim((string) $this->channel($channel)?->url);

        return $url !== '' ? $url : null;
    }

    /** Канал площадки: Telegram — основной; MAX — канал MAX (он зеркало Telegram, но участники свои). */
    private function channel(MessengerChannel $channel): ?MediaChannel
    {
        return MediaChannel::query()->where('kind', $channel === MessengerChannel::Telegram ? 'telegram' : 'max')
            ->where('is_active', true)->orderByRaw('mirror_of_channel_id IS NOT NULL')->orderBy('id')->first();
    }

    private function telegram(string $token, string $chatId, string $userId): ?bool
    {
        $r = Http::timeout(20)->withOptions(TelegramHttp::options())
            ->retry(2, 1500, fn ($e) => MediaPublisherService::isConnectFailure($e), throw: false)
            ->get(self::TELEGRAM_API.$token.'/getChatMember', ['chat_id' => $chatId, 'user_id' => $userId])->json() ?? [];
        if (! ($r['ok'] ?? false)) {
            // «user not found» / «PARTICIPANT_ID_INVALID» — человека в канале нет; прочее — не проверили.
            $d = mb_strtolower((string) ($r['description'] ?? ''));

            return str_contains($d, 'user not found') || str_contains($d, 'participant_id_invalid') ? false : null;
        }
        $m = (array) ($r['result'] ?? []);

        return in_array($m['status'] ?? '', ['creator', 'administrator', 'member'], true)
            || (($m['status'] ?? '') === 'restricted' && ! empty($m['is_member']));
    }

    private function max(string $token, string $chatId, string $userId): ?bool
    {
        $r = Http::timeout(20)->acceptJson()
            ->retry(2, 1500, fn ($e) => MediaPublisherService::isConnectFailure($e), throw: false)
            ->withOptions(['verify' => MediaPublisherService::maxVerify()])
            ->withHeaders(['Authorization' => $token])
            ->get(rtrim((string) config('services.max.api_base'), '/').'/chats/'.$chatId.'/members', ['user_ids' => $userId]);
        if (! $r->successful()) {
            return null;
        }

        return collect((array) $r->json('members', []))->contains(fn ($m) => (string) ($m['user_id'] ?? '') === (string) $userId);
    }
}
