<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessengerChannel;
use App\Http\Controllers\Controller;
use App\Jobs\Bot\HandleBotUpdateJob;
use App\Services\Bot\BotSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Вебхуки ботов заявок. Площадка присылает секрет в заголовке (его мы
 * отдали при подключении) — чужие запросы отсекаем. Обработка — в очереди:
 * мессенджер ждёт быстрого 200, а разбор ИНН и письмо с кодом — секунды.
 */
class BotWebhookController extends Controller
{
    public function telegram(Request $request, BotSettings $settings): JsonResponse
    {
        return $this->accept($request, $settings, MessengerChannel::Telegram, 'X-Telegram-Bot-Api-Secret-Token');
    }

    public function max(Request $request, BotSettings $settings): JsonResponse
    {
        return $this->accept($request, $settings, MessengerChannel::Max, 'X-Max-Bot-Api-Secret');
    }

    private function accept(Request $request, BotSettings $settings, MessengerChannel $channel, string $header): JsonResponse
    {
        if (! $settings->hasToken($channel)
            || ! hash_equals($settings->webhookSecret($channel), (string) $request->header($header, ''))) {
            return response()->json(['ok' => false], 403);
        }

        HandleBotUpdateJob::dispatch($channel->value, (array) $request->json()->all());

        return response()->json(['ok' => true]);
    }
}
