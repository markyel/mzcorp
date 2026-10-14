<?php

namespace App\Services\QuoteParts;

use App\Models\CatalogItem;
use App\Models\QuotePartsRfq;
use App\Models\Request as RequestModel;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Запрос поставщикам через QuoteParts — второй канал рядом со старой рассылкой.
 *
 * Позиции — только с M-артикулом (каталог): перед запросом они добавляются в пул и сразу
 * передаются. Поставщики — только принявшие приглашение в QuoteParts. Письмо поставщику
 * QuoteParts отправляет сам (EN + ZH, с ящика MyZip на платформе); ответы — формой или
 * письмом — возвращаются сюда снимком (refresh) и показываются в заявке.
 */
class QuotePartsRfqService
{
    public function __construct(
        private readonly QuotePartsClient $client,
        private readonly QuotePartsPoolService $pool,
    ) {}

    /**
     * @param  array<int, float>  $quantities  catalog_item_id => количество
     * @param  list<int>  $supplierIds
     */
    public function send(?RequestModel $request, array $quantities, array $supplierIds, ?string $replyBy, ?string $message, User $by): QuotePartsRfq
    {
        if (! QuotePartsPilot::enabledFor($by)) {
            throw new RuntimeException('QuoteParts выключен или вы не участник пилота.');
        }

        $catalog = CatalogItem::query()->whereIn('id', array_keys($quantities))->get(['id', 'sku'])->keyBy('id');
        $suppliers = Supplier::query()->whereIn('id', $supplierIds)->where('quoteparts_status', 'active')->pluck('id');
        if ($catalog->isEmpty() || $suppliers->isEmpty()) {
            throw new RuntimeException('Нужны позиции с M-артикулом и поставщики, принявшие приглашение в QuoteParts.');
        }

        $statuses = $this->pool->ensureInPool($catalog->keys()->all(), $by);
        $notReady = $catalog->filter(fn ($c) => ($statuses[$c->id] ?? null) !== 'active');
        if ($notReady->isNotEmpty()) {
            throw new RuntimeException('Позиции не в работе в QuoteParts (на паузе или не переданы): '.$notReady->pluck('sku')->implode(', ').'.');
        }

        $result = $this->client->createRfq([
            'items' => $catalog->map(fn ($c) => ['code' => $c->sku, 'quantity' => max(0.001, (float) $quantities[$c->id]), 'note' => null])->values()->all(),
            'suppliers' => $suppliers->map(fn ($id) => (string) $id)->values()->all(),
            'reply_by' => $replyBy,
            'message' => $message ?: null,
            'external_ref' => $request?->internal_code,
        ]);

        return QuotePartsRfq::create([
            'remote_id' => $result['id'],
            'tag' => $result['tag'] ?? null,
            'request_id' => $request?->id,
            'created_by_user_id' => $by->id,
            'reply_by' => $result['reply_by'] ?? $replyBy,
            'status' => 'open',
            'snapshot' => $result,
            'synced_at' => now(),
        ]);
    }

    public function refresh(QuotePartsRfq $rfq): QuotePartsRfq
    {
        $snapshot = $this->client->rfq($rfq->remote_id);
        $rfq->forceFill([
            'snapshot' => $snapshot,
            'status' => $snapshot['status'] ?? $rfq->status,
            'reply_by' => $snapshot['reply_by'] ?? $rfq->reply_by,
            'synced_at' => now(),
        ])->save();

        return $rfq;
    }

    /**
     * Обновить снимки запросов, по которым ещё могут прийти ответы (открытые и
     * закрытые за последние 30 дней — поздние ответы письмом тоже становятся офферами).
     */
    public function refreshRecent(): int
    {
        $n = 0;
        foreach (QuotePartsRfq::query()->where('created_at', '>=', now()->subDays(60))
            ->where(fn ($q) => $q->where('status', 'open')->orWhere('synced_at', '>=', now()->subDays(30)))
            ->orderBy('synced_at')->limit(200)->get() as $rfq) {
            try {
                $this->refresh($rfq);
                $n++;
            } catch (Throwable $e) {
                Log::warning('QuoteParts rfq refresh failed', ['rfq' => $rfq->remote_id, 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }
}
