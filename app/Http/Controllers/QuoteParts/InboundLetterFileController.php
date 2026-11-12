<?php

namespace App\Http\Controllers\QuoteParts;

use App\Http\Controllers\Controller;
use App\Services\QuoteParts\QuotePartsClient;
use Illuminate\Http\Response;
use Throwable;

/**
 * Вложение письма, которое QuoteParts не привязал к запросу (вкладка «Письма без запроса»):
 * QuoteParts отдаёт его по ключу API, сотруднику — через mzcorp.
 */
class InboundLetterFileController extends Controller
{
    public function __invoke(int $letter, int $index, QuotePartsClient $client): Response
    {
        try {
            [$body, $name, $type] = $client->inboundLetterFile($letter, $index);
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
