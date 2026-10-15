<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\QuoteParts\HandleQuotePartsEventJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Уведомления QuoteParts о наших запросах поставщикам (вместо опроса раз в 10 минут):
 * supplier.replied, supplier.question, rfq.updated. Подпись — HMAC-SHA256 по
 * «timestamp.body» секретом services.quoteparts.webhook_secret; старше допуска — отказ.
 * Сама обработка (снимок запроса + уведомление автору) — в очереди.
 */
class QuotePartsWebhookController extends Controller
{
    public const EVENTS = ['supplier.replied', 'supplier.question', 'rfq.updated'];

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.quoteparts.webhook_secret');
        $timestamp = (string) $request->header('X-QuoteParts-Timestamp', '');
        $signature = (string) $request->header('X-QuoteParts-Signature', '');
        $body = $request->getContent();

        if ($secret === '' || $timestamp === '' || ! ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > (int) config('services.quoteparts.webhook_tolerance', 300)
            || ! hash_equals('sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature)) {
            Log::warning('QuoteParts webhook rejected', ['ip' => $request->ip(), 'has_secret' => $secret !== '']);

            return response()->json(['message' => 'Bad signature.'], 401);
        }

        $data = json_decode($body, true);
        $event = is_array($data) ? (string) ($data['event'] ?? '') : '';
        if (! in_array($event, self::EVENTS, true) || ! is_int($data['rfq_id'] ?? null)) {
            return response()->json(['message' => 'Unknown event.'], 422);
        }

        HandleQuotePartsEventJob::dispatch(
            $data['rfq_id'],
            $event,
            is_string($data['supplier'] ?? null) ? $data['supplier'] : null,
            array_values(array_filter((array) ($data['questions'] ?? []), 'is_string')),
        );

        return response()->json(['ok' => true], 202);
    }
}
