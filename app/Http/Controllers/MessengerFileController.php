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
        $user = $http->user();
        // Живой чат без заявки (взят из раздела «Боты»): файлы видит тот, кто ведёт чат, и руководство.
        $allowed = $request !== null
            ? $request->isViewableBy($user)
            : ((int) $message->chat?->taken_by_user_id === (int) $user->id
                || $user->hasAnyRole([\App\Enums\Role::HeadOfSales->value, \App\Enums\Role::Director->value, \App\Enums\Role::Admin->value]));
        abort_unless($allowed, 403);
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
