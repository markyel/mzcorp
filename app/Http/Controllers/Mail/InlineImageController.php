<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\EmailMessage;
use App\Services\Mail\MailInlineImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Загрузка картинки в тело письма из редактора почтового клиента.
 * Только автор черновика; ответ — URL для <img src> (inline-роут вложений).
 */
class InlineImageController extends Controller
{
    public function store(Request $request, int $draft, MailInlineImageService $images): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $message = EmailMessage::query()
            ->where('is_draft', true)
            ->where('draft_author_user_id', $user->id)
            ->find($draft);
        if (! $message) {
            return response()->json(['message' => 'Черновик не найден или уже отправлен.'], 404);
        }

        $request->validate([
            'image' => 'required|file|mimes:png,jpg,jpeg,gif|max:' . (int) (MailInlineImageService::MAX_BYTES / 1024),
        ], [
            'image.mimes' => 'Поддерживаются PNG, JPG и GIF.',
            'image.max' => 'Картинка больше 10 МБ.',
        ]);

        try {
            $attachment = $images->store($message, $request->file('image'));
        } catch (\Throwable $e) {
            Log::warning('InlineImageController: store failed', [
                'draft_id' => $message->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage() ?: 'Не удалось сохранить картинку.'], 422);
        }

        return response()->json([
            'id' => $attachment->id,
            'cid' => $attachment->content_id,
            'url' => $images->urlFor($attachment),
            'width' => null,
        ]);
    }
}
