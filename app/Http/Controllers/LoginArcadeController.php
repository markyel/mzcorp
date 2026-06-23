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
    /**
     * Список героев: id, имя (первое слово), карта URL по эмоциям
     * (neutral всегда; won/lost — только если загружены). Игра по этим
     * вариантам показывает радость при «поймал» и грусть при 💣.
     */
    public function roster()
    {
        $heroes = User::query()
            ->whereNotNull('avatar_neutral_path')
            ->orderBy('name')
            ->get(['id', 'name', 'avatar_neutral_path', 'avatar_won_path', 'avatar_lost_path'])
            ->map(function (User $u) {
                $urls = [];
                foreach (User::AVATAR_VARIANTS as $variant) {
                    if ($u->hasAvatar($variant)) {
                        $urls[$variant] = route('arcade.avatar', [$u->id, $variant]);
                    }
                }

                return [
                    'id' => $u->id,
                    'name' => (string) Str::of((string) $u->name)->trim()->explode(' ')->first(),
                    'urls' => $urls,
                ];
            })
            ->values();

        return response()->json($heroes);
    }

    /** Публичная отдача аватарки героя в нужной эмоции (neutral|won|lost). */
    public function avatar(User $user, string $variant): Response|BinaryFileResponse
    {
        abort_unless(in_array($variant, User::AVATAR_VARIANTS, true), 404);

        $path = $user->avatarPath($variant);
        abort_if($path === null, 404);

        $disk = Storage::disk(User::AVATAR_DISK);
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $disk->mimeType($path) ?: 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
