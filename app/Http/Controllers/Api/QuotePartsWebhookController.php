<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\QuoteParts\HandleQuotePartsConversationJob;
use App\Jobs\QuoteParts\HandleQuotePartsEventJob;
use App\Jobs\QuoteParts\HandleQuotePartsPriceListJob;
use App\Jobs\QuoteParts\SyncQuotePartsDeclinesJob;
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

    /** Подпись QuoteParts: HMAC-SHA256 по «timestamp.body», не старше допуска. */
    public static function signed(Request $request): bool
    {
        $secret = (string) config('services.quoteparts.webhook_secret');
        $timestamp = (string) $request->header('X-QuoteParts-Timestamp', '');
        $signature = (string) $request->header('X-QuoteParts-Signature', '');

        $ok = $secret !== '' && $timestamp !== '' && ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= (int) config('services.quoteparts.webhook_tolerance', 300)
            && hash_equals('sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $signature);
        if (! $ok) {
            Log::warning('QuoteParts call rejected', ['ip' => $request->ip(), 'path' => $request->path(), 'has_secret' => $secret !== '']);
        }

        return $ok;
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (! self::signed($request)) {
            return response()->json(['message' => 'Bad signature.'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $event = is_array($data) ? (string) ($data['event'] ?? '') : '';

        // Поставщик прислал прайс: копия офферов и уведомление снабжению.
        if ($event === 'price_list.matched' && is_int($data['price_list_id'] ?? null)) {
            HandleQuotePartsPriceListJob::dispatch(array_intersect_key($data, array_flip(['price_list_id', 'supplier', 'stats'])));

            return response()->json(['ok' => true], 202);
        }

        // Поставщик отметил позиции «не сможем предложить» или вернул их — копия для «Снабжения».
        if ($event === 'supplier.items_declined') {
            SyncQuotePartsDeclinesJob::dispatch();

            return response()->json(['ok' => true], 202);
        }

        // Поставщик написал в обращении вне запросов — сказать автору обращения или снабжению.
        if ($event === 'conversation.message' && is_int($data['conversation_id'] ?? null)) {
            HandleQuotePartsConversationJob::dispatch(array_intersect_key($data, array_flip([
                'conversation_id', 'tag', 'topic', 'subject', 'started_by', 'crm_author_ref', 'supplier', 'preview',
            ])));

            return response()->json(['ok' => true], 202);
        }

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
