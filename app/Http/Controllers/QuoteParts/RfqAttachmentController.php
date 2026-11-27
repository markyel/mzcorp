<?php

namespace App\Http\Controllers\QuoteParts;

use App\Http\Controllers\Controller;
use App\Services\QuoteParts\QuotePartsClient;
use Illuminate\Http\Response;
use Throwable;

/**
 * Файл, ушедший поставщикам с самим запросом через QuoteParts (фото из каталога, загруженное снабженцем):
 * видно в переписке по запросу, какие фото получил поставщик.
 */
class RfqAttachmentController extends Controller
{
    public function __invoke(int $rfq, int $index, QuotePartsClient $client): Response
    {
        try {
            [$body, $name, $type] = $client->rfqAttachment($rfq, $index);
        } catch (Throwable) {
            abort(404);
        }

        // Картинки и PDF открываются в браузере, остальное (и SVG) — скачивается.
        $inline = in_array($type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'], true);

        return response($body, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.str_replace('"', '', $name).'"; filename*=UTF-8\'\''.rawurlencode($name),
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
