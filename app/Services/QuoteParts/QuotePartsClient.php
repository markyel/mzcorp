<?php

namespace App\Services\QuoteParts;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP-клиент API QuoteParts. Только транспорт: что и когда слать — QuotePartsPoolService.
 */
class QuotePartsClient
{
    public function isConfigured(): bool
    {
        return (string) config('services.quoteparts.token') !== '';
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{code:string, status:string, version:int, result:string, reasons:list<string>}>
     */
    public function syncItems(array $items): array
    {
        $response = $this->http()->post('/api/v1/items/sync', ['items' => $items]);

        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts items/sync: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 500));
        }

        return $response->json('items', []);
    }

    /**
     * @param  list<string>  $codes
     * @return array{archived: list<string>, unknown: list<string>}
     */
    public function archiveItems(array $codes): array
    {
        $response = $this->http()->post('/api/v1/items/archive', ['codes' => $codes]);

        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts items/archive: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 500));
        }

        return ['archived' => $response->json('archived', []), 'unknown' => $response->json('unknown', [])];
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{code:string, status:string, version:int, reasons:list<string>}>
     */
    public function itemStatuses(array $codes): array
    {
        $response = $this->http()->post('/api/v1/items/statuses', ['codes' => $codes]);

        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts items/statuses: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 500));
        }

        return $response->json('items', []);
    }

    private function http(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('QuoteParts не настроен: нет QUOTEPARTS_API_TOKEN.');
        }

        return Http::baseUrl(rtrim((string) config('services.quoteparts.url'), '/'))
            ->withToken((string) config('services.quoteparts.token'))
            ->acceptJson()
            ->timeout((int) config('services.quoteparts.timeout', 30))
            ->retry(2, 1000, throw: false);
    }
}
