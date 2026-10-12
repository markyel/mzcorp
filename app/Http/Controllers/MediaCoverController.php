<?php

namespace App\Http\Controllers;

use App\Services\Marketing\MediaCoverService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Обложка поста медиаплана (MediaCoverService). Публично: картинку забирают
 * наш же публикатор (HEAD + скачивание) и лента /news. Имя — uuid из маршрута,
 * путь собирается внутри, выйти из папки обложек нельзя.
 */
class MediaCoverController extends Controller
{
    public function show(string $name): BinaryFileResponse
    {
        abort_unless(MediaCoverService::validName($name), 404);
        $path = MediaCoverService::path($name);
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=2592000, immutable',
        ]);
    }
}
