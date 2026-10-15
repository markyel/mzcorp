<?php

namespace App\Http\Controllers\QuoteParts;

use App\Http\Controllers\Controller;
use App\Services\QuoteParts\QuotePartsClient;
use Illuminate\Http\Response;
use Throwable;

/**
 * Фото предложенного поставщиком товара (копия, восстановленное, аналог): QuoteParts отдаёт
 * его по ключу API, сотруднику — через mzcorp.
 */
class OfferPhotoController extends Controller
{
    public function __invoke(int $offer, int $index, QuotePartsClient $client): Response
    {
        try {
            [$body, $type] = $client->offerPhoto($offer, $index);
        } catch (Throwable) {
            abort(404);
        }
        abort_unless(str_starts_with($type, 'image/'), 404);

        return response($body, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
