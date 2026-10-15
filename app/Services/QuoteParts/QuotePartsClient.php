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
        // Пачка позиций обрабатывается дольше обычного запроса — свой таймаут.
        $response = $this->http()->timeout((int) config('services.quoteparts.sync_timeout', 120))->post('/api/v1/items/sync', ['items' => $items]);

        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts items/sync: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 500));
        }

        return $response->json('items', []);
    }

    /**
     * Предварительные позиции (новый товар без M-артикула): временный код на каждую ссылку ref.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{ref:string, code:string, status:string, result:string}>
     */
    public function createDrafts(array $items): array
    {
        $response = $this->http()->post('/api/v1/items/drafts', ['items' => $items]);

        if ($response->status() === 422) {
            throw new RuntimeException(collect($response->json('errors', []))->flatten()->implode(' ') ?: 'QuoteParts не принял позиции.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts items/drafts: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 300));
        }

        return $response->json('items', []);
    }

    /**
     * Предварительные позиции → постоянные: карточка как в sync + draft_code.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{draft_code:string, code:?string, result:string}>
     */
    public function promoteDrafts(array $items): array
    {
        $response = $this->http()->post('/api/v1/items/promote', ['items' => $items]);

        if ($response->status() === 422) {
            throw new RuntimeException(collect($response->json('errors', []))->flatten()->implode(' ') ?: 'QuoteParts не принял позиции.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts items/promote: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 300));
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
     * Режим переписки: завести поставщика (или ещё один его адрес) в QuoteParts без письма-приглашения.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function registerSupplier(array $payload): array
    {
        $response = $this->http()->post('/api/v1/supplier-links', $payload);

        if ($response->status() === 422) {
            throw new RuntimeException(collect($response->json('errors', []))->flatten()->implode(' ') ?: 'QuoteParts не принял поставщика.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts supplier-links: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 300));
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
     * @param  array{dispatch_mode:string, escalate_after_hours:int, contacts: list<array{email:string, name:?string, topics:list<string>, position:int}>}  $payload
     * @return array<string, mixed> the link as QuoteParts returns it
     */
    public function setContacts(string $key, array $payload): array
    {
        $response = $this->http()->put('/api/v1/supplier-links/'.rawurlencode($key).'/contacts', $payload);

        if ($response->status() === 422) {
            throw new RuntimeException(collect($response->json('errors', []))->flatten()->implode(' ') ?: 'QuoteParts не принял контакты.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts contacts: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 300));
        }

        return $response->json();
    }

    /**
     * Какие группы товаров (cn / ru / eu) поставщик видит в кабинете; null — все.
     *
     * @param  list<string>|null  $groups
     * @return array<string, mixed> связь, как её вернул QuoteParts
     */
    public function setSupplierGroups(string $key, ?array $groups): array
    {
        $response = $this->http()->put('/api/v1/supplier-links/'.rawurlencode($key).'/groups', ['groups' => $groups]);
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts groups: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 300));
        }

        return $response->json();
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
     * Наш ответ поставщику по запросу (обычно на его вопрос): письмо в его переписку.
     *
     * @return array<string, mixed> обновлённый снимок запроса
     */
    public function answerSupplier(int $remoteId, string $externalId, string $text, ?string $authorName): array
    {
        $response = $this->http()->post('/api/v1/rfqs/'.$remoteId.'/suppliers/'.rawurlencode($externalId).'/answer', [
            'text' => $text,
            'author_name' => $authorName,
        ]);

        if (in_array($response->status(), [422, 502], true)) {
            throw new RuntimeException((string) ($response->json('message') ?: collect($response->json('errors', []))->flatten()->implode(' ')) ?: 'QuoteParts не отправил ответ.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts answer: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 300));
        }

        return $response->json();
    }

    /**
     * Ответ на группу одинаковых вопросов: каждому спросившему — в его переписку, текст по поставщику.
     *
     * @param  list<array{supplier:string, text:string}>  $answers
     * @return array<string, mixed> обновлённый снимок запроса
     */
    public function answerQuestionGroup(int $remoteId, int $groupId, array $answers, ?string $memory, ?string $authorName): array
    {
        $response = $this->http()->post('/api/v1/rfqs/'.$remoteId.'/question-groups/'.$groupId.'/answer', [
            'answers' => $answers,
            'memory' => $memory,
            'author_name' => $authorName,
        ]);

        if (in_array($response->status(), [422, 502], true)) {
            throw new RuntimeException((string) ($response->json('message') ?: collect($response->json('errors', []))->flatten()->implode(' ')) ?: 'QuoteParts не отправил ответ.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts group answer: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 300));
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

    /**
     * Обращения к поставщику вне запросов (переписка по темам).
     *
     * @return list<array<string, mixed>>
     */
    public function conversations(string $supplierKey): array
    {
        $response = $this->http()->get('/api/v1/conversations', ['supplier' => $supplierKey]);
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts conversations: HTTP '.$response->status());
        }

        return $response->json('conversations', []);
    }

    /**
     * @param  array<string, mixed>  $fields  supplier, topic, subject, text, author_ref, author_name, external_ref
     * @param  list<array{path:string, name:string}>  $files  локальные файлы
     * @return array<string, mixed>
     */
    public function startConversation(array $fields, array $files = []): array
    {
        return $this->multipart('/api/v1/conversations', $fields, $files, 'conversations');
    }

    /**
     * @param  list<array{path:string, name:string}>  $files
     * @return array<string, mixed>
     */
    public function sendConversationMessage(int $id, string $text, ?string $authorName, array $files = []): array
    {
        return $this->multipart('/api/v1/conversations/'.$id.'/messages', ['text' => $text, 'author_name' => $authorName], $files, 'conversation message');
    }

    /** @return array<string, mixed> */
    public function closeConversation(int $id): array
    {
        $response = $this->http()->post('/api/v1/conversations/'.$id.'/close');
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts close conversation: HTTP '.$response->status());
        }

        return $response->json();
    }

    /** Файл сообщения обращения: [содержимое, имя, тип]. @return array{0:string, 1:string, 2:string} */
    public function conversationFile(int $id, int $messageId, int $index): array
    {
        $response = $this->http()->get('/api/v1/conversations/'.$id.'/messages/'.$messageId.'/attachments/'.$index);
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts file: HTTP '.$response->status());
        }
        $name = 'file';
        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', (string) $response->header('Content-Disposition'), $m)) {
            $name = rawurldecode($m[1]);
        }

        return [$response->body(), $name, (string) ($response->header('Content-Type') ?: 'application/octet-stream')];
    }

    /** Фото предложения поставщика: [содержимое, тип]. @return array{0:string, 1:string} */
    public function offerPhoto(int $offerId, int $index): array
    {
        $response = $this->http()->get('/api/v1/offers/'.$offerId.'/photos/'.$index);
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts offer photo: HTTP '.$response->status());
        }

        return [$response->body(), (string) ($response->header('Content-Type') ?: 'application/octet-stream')];
    }

    /**
     * Офферы поставщиков, изменившиеся с момента $since (null — все).
     *
     * @return list<array<string, mixed>>
     */
    public function offers(?string $since = null): array
    {
        $response = $this->http()->get('/api/v1/offers', array_filter(['since' => $since]));
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts offers: HTTP '.$response->status());
        }

        return $response->json('offers', []);
    }

    /**
     * Отметки поставщиков «не сможем предложить» (в силе и снятые), изменённые с момента $since.
     *
     * @return list<array<string, mixed>>
     */
    public function itemDeclines(?string $since = null): array
    {
        $response = $this->http()->get('/api/v1/item-declines', array_filter(['since' => $since]));
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts item declines: HTTP '.$response->status());
        }

        return $response->json('declines', []);
    }

    /** @return list<array<string, mixed>> */
    public function priceLists(): array
    {
        $response = $this->http()->get('/api/v1/price-lists');
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts price lists: HTTP '.$response->status());
        }

        return $response->json('price_lists', []);
    }

    /** @return array<string, mixed> */
    public function priceList(int $id, ?string $status = null): array
    {
        $response = $this->http()->get('/api/v1/price-lists/'.$id, array_filter(['status' => $status]));
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts price list: HTTP '.$response->status());
        }

        return $response->json();
    }

    /** @return array<string, mixed> */
    public function acceptPriceListRow(int $id, int $rowId, ?string $code): array
    {
        $response = $this->http()->post('/api/v1/price-lists/'.$id.'/rows/'.$rowId.'/accept', array_filter(['code' => $code]));
        if ($response->status() === 422) {
            throw new RuntimeException(collect($response->json('errors', []))->flatten()->implode(' ') ?: 'QuoteParts не принял строку.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts accept row: HTTP '.$response->status());
        }

        return $response->json();
    }

    /** @return array<string, mixed> */
    public function rejectPriceListRow(int $id, int $rowId): array
    {
        $response = $this->http()->post('/api/v1/price-lists/'.$id.'/rows/'.$rowId.'/reject');
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts reject row: HTTP '.$response->status());
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<array{path:string, name:string}>  $files
     * @return array<string, mixed>
     */
    private function multipart(string $url, array $fields, array $files, string $what): array
    {
        $request = $this->http();
        foreach ($files as $file) {
            $request = $request->attach('files[]', (string) file_get_contents($file['path']), $file['name']);
        }
        $response = $request->post($url, array_filter($fields, fn ($v) => $v !== null));

        if ($response->status() === 422) {
            throw new RuntimeException(collect($response->json('errors', []))->flatten()->implode(' ') ?: 'QuoteParts не принял '.$what.'.');
        }
        if ($response->status() === 502 && $response->json('message')) {
            throw new RuntimeException((string) $response->json('message'));
        }
        if (! $response->successful()) {
            throw new RuntimeException('QuoteParts '.$what.': HTTP '.$response->status().' '.mb_substr($response->body(), 0, 300));
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
