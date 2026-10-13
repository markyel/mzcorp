<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Services\Bot\BotChatSignalService;
use App\Services\Mail\NewMailSignalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Опрос «есть ли новая почта» из любого раздела CRM (layouts/app.blade.php).
 * ?after=<id> — курсор, который клиент запомнил в прошлый раз.
 * Заодно — сообщения клиентов из Telegram/MAX (chat, курсор ?chat_after=):
 * один опрос вместо двух.
 */
class NewMailSignalController extends Controller
{
    public function __invoke(Request $request, NewMailSignalService $signal, BotChatSignalService $chats): JsonResponse
    {
        $summary = $signal->summary($request->user(), self::cursor($request->query('after')));
        $summary['chat'] = $chats->summary($request->user(), self::cursor($request->query('chat_after')));

        return response()->json($summary)->header('Cache-Control', 'no-store');
    }

    private static function cursor(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
