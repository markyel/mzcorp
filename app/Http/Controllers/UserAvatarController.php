<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Отдача аватарки пользователя с диска `local` (private). Вариант —
 * neutral | won | lost (валидируется в роуте). Долгий браузерный кэш;
 * версия в URL (?v=) обеспечивает сброс кэша при замене файла.
 */
class UserAvatarController extends Controller
{
    /** TTL браузерного кэша (30 дней). */
    private const BROWSER_TTL = 30 * 24 * 3600;

    public function show(User $user, string $variant): Response|BinaryFileResponse
    {
        if (! in_array($variant, User::AVATAR_VARIANTS, true)) {
            abort(404);
        }

        $path = $user->avatarPath($variant);
        if ($path === null) {
            abort(404);
        }

        $disk = Storage::disk(User::AVATAR_DISK);
        if (! $disk->exists($path)) {
            abort(404);
        }

        $mime = $disk->mimeType($path) ?: 'image/png';

        return response()->file($disk->path($path), [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=' . self::BROWSER_TTL,
        ]);
    }
}
