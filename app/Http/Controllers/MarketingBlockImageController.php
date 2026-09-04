<?php

namespace App\Http\Controllers;

use App\Models\MarketingBlock;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Картинка рекламного блока — для превью в админке и для показа в треде CRM
 * (body_html отправленного письма ссылается на этот URL; в самом письме
 * картинка встроена как CID). Файл лежит на private-диске, роут за auth.
 */
class MarketingBlockImageController extends Controller
{
    private const BROWSER_TTL = 7 * 24 * 3600;

    public function show(MarketingBlock $block): Response|BinaryFileResponse
    {
        $path = $block->image_path;
        if (! $path) {
            abort(404);
        }
        $disk = Storage::disk(MarketingBlock::IMAGE_DISK);
        if (! $disk->exists($path)) {
            abort(404);
        }

        return response()->file($disk->path($path), [
            'Content-Type' => $disk->mimeType($path) ?: 'image/png',
            'Cache-Control' => 'private, max-age='.self::BROWSER_TTL,
        ]);
    }
}
