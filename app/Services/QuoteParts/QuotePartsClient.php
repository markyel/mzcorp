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

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id:int, external_id:?string, status:string, terms_confirmed:bool, supplier_comment:?string}
     */
    public function inviteSupplier(array $payload): array
    {
        $response = $this->http()->post('/api/v1/supplier-invitations', $payload);

        if ($response->status() === 422) {
            throw new RuntimeException(collect($response->json('errors', []))->flatten()->implode(' ') ?: 'Проверьте данные приглашения.');
        }
        if ($response->status() === 503 && $response->json('message')) {
            throw new RuntimeException((string) $response->json('message'));
        }
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts supplier-invitations: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 500));
        }

        return $response->json();
    }

    /**
     * @param  list<string>  $externalIds
     * @return list<array{id:int, external_id:?string, status:string, terms_confirmed:bool, supplier_comment:?string}>
     */
    public function supplierLinks(array $externalIds): array
    {
        $response = $this->http()->get('/api/v1/supplier-links', ['external_ids' => $externalIds]);

        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts supplier-links: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 500));
        }

        return $response->json('links', []);
    }

    /**
     * @param  array{items: list<array{code:string, quantity:float, note:?string}>, suppliers: list<string>, reply_by:?string, message:?string, external_ref:?string}  $payload
     * @return array<string, mixed>
     */
    public function createRfq(array $payload): array
    {
        $response = $this->http()->post('/api/v1/rfqs', $payload);

        if ($response->status() === 422) {
            throw new RuntimeException(collect($response->json('errors', []))->flatten()->implode(' ') ?: 'QuoteParts не принял запрос.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts rfqs: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 500));
        }

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function rfq(int $remoteId): array
    {
        $response = $this->http()->get('/api/v1/rfqs/'.$remoteId);

        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts rfq '.$remoteId.': HTTP '.$response->status());
        }

        return $response->json();
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
