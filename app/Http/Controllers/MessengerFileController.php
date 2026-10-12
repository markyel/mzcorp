<?php

namespace App\Http\Controllers;

use App\Models\MessengerMessage;
use App\Services\Bot\BotIntakeStore;
use App\Services\Bot\BotThreadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Файл из переписки с клиентом в мессенджере (фото и документы клиента и
 * менеджера). Доступ — как к заявке: кто видит заявку, видит и её файлы.
 */
class MessengerFileController extends Controller
{
    public function show(Request $http, MessengerMessage $message, int $index): StreamedResponse
    {
        $request = $message->request;
        abort_unless($request !== null && $request->isViewableBy($http->user()), 403);
        $att = BotThreadService::attachment($message, $index);
        abort_if($att === null, 404);

        $mime = (string) ($att['mime'] ?: 'application/octet-stream');
        $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';

        return Storage::disk(BotIntakeStore::DISK)->response($att['path'], (string) $att['name'], [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=86400',
        ], $inline ? 'inline' : 'attachment');
    }
}
