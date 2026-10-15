<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QuoteParts\QuotePartsPriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QuoteParts спрашивает, какая это наша позиция (строки прайса поставщика, которых нет в
 * пуле): ищем по всему каталогу mzcorp, найденное добавляем в пул. Подпись — как у вебхуков.
 */
class QuotePartsResolveController extends Controller
{
    public function __invoke(Request $request, QuotePartsPriceService $prices): JsonResponse
    {
        if (! QuotePartsWebhookController::signed($request)) {
            return response()->json(['message' => 'Bad signature.'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $rows = collect(is_array($data) ? (array) ($data['rows'] ?? []) : [])
            ->filter(fn ($r) => is_array($r) && is_numeric($r['ref'] ?? null))
            ->take(500)
            ->map(fn ($r) => [
                'ref' => (int) $r['ref'],
                'our_code' => is_string($r['our_code'] ?? null) ? $r['our_code'] : null,
                'part_number' => is_string($r['part_number'] ?? null) ? $r['part_number'] : null,
                'brand' => is_string($r['brand'] ?? null) ? $r['brand'] : null,
                'name' => is_string($r['name'] ?? null) ? $r['name'] : null,
            ])->values()->all();

        return response()->json(['rows' => $prices->resolve($rows)]);
    }
}
