<?php

namespace App\Http\Controllers\QuoteParts;

use App\Http\Controllers\Controller;
use App\Services\QuoteParts\QuotePartsClient;
use Illuminate\Http\Response;
use Throwable;

/**
 * Файл из переписки с поставщиком по запросу через QuoteParts (письмо поставщика, вопрос из
 * кабинета): QuoteParts отдаёт его по ключу API, сотруднику — через mzcorp.
 */
class RfqFileController extends Controller
{
    public function __invoke(int $rfq, int $message, int $index, QuotePartsClient $client): Response
    {
        try {
            [$body, $name, $type] = $client->rfqFile($rfq, $message, $index);
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
