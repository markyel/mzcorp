<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Публичный (БЕЗ auth) эндпоинт для мини-аркады «Лови заявки» на странице
 * входа: ростер «героев» (сотрудники с neutral-аватаркой) + отдача самой
 * аватарки. Отдаём только картинку + имя — ничего чувствительного.
 * Развлекательная фича «размяться перед сменой».
 */
class LoginArcadeController extends Controller
{
    /** Список героев: id, имя (только первое слово), URL neutral-аватарки. */
    public function roster()
    {
        $heroes = User::query()
            ->whereNotNull('avatar_neutral_path')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => (string) Str::of((string) $u->name)->trim()->explode(' ')->first(),
                'url' => route('arcade.avatar', $u->id),
            ])
            ->values();

        return response()->json($heroes);
    }

    /** Публичная отдача neutral-аватарки героя (только если она есть). */
    public function avatar(User $user): Response|BinaryFileResponse
    {
        $path = $user->avatarPath('neutral');
        abort_if($path === null, 404);

        $disk = Storage::disk(User::AVATAR_DISK);
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $disk->mimeType($path) ?: 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
