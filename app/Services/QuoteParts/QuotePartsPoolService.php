<?php

namespace App\Services\QuoteParts;

use App\Models\CatalogItem;
use App\Models\CatalogSupplierPrice;
use App\Models\QuotePartsPoolItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Пул позиций, работа по которым передана в QuoteParts.
 *
 * Передаётся только часть карточки, нужная для работы с поставщиками, и первая/последняя
 * закупка из 1С. Цены продажи, остатки и клиенты не уходят. После каждой загрузки
 * каталога (sync()) досылаются только позиции, у которых изменилась передаваемая часть;
 * масштаб изменения оценивает QuoteParts и при существенном ставит позицию на паузу.
 */
class QuotePartsPoolService
{
    /** Размеры каталога → характеристики в QuoteParts. */
    private const SIZE_LABELS = [
        'size_a' => 'Размер A, мм',
        'size_b' => 'Размер B, мм',
        'size_c' => 'Размер C, мм',
        'size_d' => 'Размер D, мм',
        'size_e' => 'Размер E, мм',
        'size_f' => 'Размер F, мм',
    ];

    public function __construct(private readonly QuotePartsClient $client) {}

    /**
     * Добавить позиции в пул и сразу передать их.
     *
     * @param  list<int>  $catalogItemIds
     * @return array{added:int, already:int}
     */
    public function add(array $catalogItemIds, ?User $user): array
    {
        $items = CatalogItem::query()->whereIn('id', $catalogItemIds)->get(['id', 'sku']);
        $added = 0;
        $already = 0;

        foreach ($items as $item) {
            $row = QuotePartsPoolItem::firstOrNew(['catalog_item_id' => $item->id]);

            if ($row->exists && $row->removed_at === null) {
                $already++;

                continue;
            }

            $row->fill([
                'sku' => $item->sku,
                'status' => QuotePartsPoolItem::STATUS_PENDING,
                'removed_at' => null,
                'payload_hash' => null,
                'added_by_user_id' => $user?->id,
                'last_error' => null,
            ])->save();
            $added++;
        }

        return ['added' => $added, 'already' => $already];
    }

    /**
     * Убрать позиции из пула: в QuoteParts они уходят в архив, история там сохраняется.
     *
     * @param  list<int>  $poolItemIds
     */
    public function remove(array $poolItemIds): int
    {
        $rows = QuotePartsPoolItem::query()->inPool()->whereIn('id', $poolItemIds)->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $this->client->archiveItems($rows->pluck('sku')->values()->all());

        QuotePartsPoolItem::query()->whereIn('id', $rows->pluck('id'))->update([
            'status' => QuotePartsPoolItem::STATUS_ARCHIVED,
            'removed_at' => now(),
            'synced_at' => now(),
        ]);

        return $rows->count();
    }

    /**
     * Передать новые позиции пула и досылать изменившиеся.
     *
     * @return array{sent:int, unchanged:int, failed:int, paused:int}
     */
    public function sync(): array
    {
        $stats = ['sent' => 0, 'unchanged' => 0, 'failed' => 0, 'paused' => 0];

        QuotePartsPoolItem::query()->inPool()->with('catalogItem')
            ->chunkById(max(1, (int) config('services.quoteparts.batch', 200)), function (Collection $rows) use (&$stats) {
                $this->syncChunk($rows, $stats);
            });

        $this->refreshPausedStatuses();

        return $stats;
    }

    /**
     * Решение по позиции на паузе принимают в QuoteParts — подтягиваем статус обратно.
     */
    public function refreshPausedStatuses(): int
    {
        $updated = 0;

        QuotePartsPoolItem::query()->inPool()->where('status', QuotePartsPoolItem::STATUS_PAUSED)
            ->chunkById(max(1, (int) config('services.quoteparts.batch', 200)), function (Collection $rows) use (&$updated) {
                try {
                    $statuses = collect($this->client->itemStatuses($rows->pluck('sku')->values()->all()))->keyBy('code');
                } catch (Throwable $e) {
                    Log::warning('QuoteParts status refresh failed', ['error' => $e->getMessage()]);

                    return false;
                }

                foreach ($rows as $row) {
                    $s = $statuses->get($row->sku);
                    if ($s === null || $s['status'] === $row->status) {
                        continue;
                    }
                    $row->forceFill([
                        'status' => $s['status'],
                        'remote_version' => $s['version'] ?? $row->remote_version,
                        'pause_reasons' => $s['status'] === QuotePartsPoolItem::STATUS_PAUSED ? ($s['reasons'] ?? []) : null,
                    ])->save();
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * Передаваемая часть карточки.
     *
     * @return array<string, mixed>
     */
    public function payloadFor(CatalogItem $item, Collection $purchasePrices): array
    {
        $partNumbers = [];
        $brands = (array) ($item->brands ?? []);

        foreach ((array) ($item->articles ?? []) as $i => $article) {
            if (trim((string) $article) === '' || CatalogItem::isInternalCode((string) $article, $item->sku)) {
                continue;
            }
            $partNumbers[] = ['brand' => ($b = trim((string) ($brands[$i] ?? ''))) === '' ? null : $b, 'number' => trim((string) $article)];
        }

        $specs = [];
        foreach (self::SIZE_LABELS as $column => $label) {
            if ($item->{$column} !== null && (float) $item->{$column} > 0) {
                $specs[$label] = (float) $item->{$column};
            }
        }
        if (trim((string) $item->form_factor) !== '') {
            $specs['Исполнение'] = trim((string) $item->form_factor);
        }

        $mpn = trim((string) $item->brand_article);

        return [
            'code' => $item->sku,
            'external_id' => (string) $item->id,
            'name' => trim((string) $item->name),
            'name_en' => trim((string) $item->name_en) ?: null,
            'brand' => trim((string) $item->brand) ?: null,
            'mpn' => $mpn === '' || CatalogItem::isInternalCode($mpn, $item->sku) ? null : $mpn,
            'part_numbers' => $partNumbers,
            'category' => trim((string) $item->part_type) ?: null,
            'weight_kg' => $item->weight !== null && (float) $item->weight > 0 ? (float) $item->weight : null,
            'specs' => $specs,
            'photo_url' => $item->photo_url ?: null,
            'purchase_prices' => $purchasePrices->map(fn (CatalogSupplierPrice $p) => [
                'kind' => $p->kind,
                'supplier_name' => $p->supplier_name_1c,
                'price' => (float) $p->price,
                'currency' => $p->currency ?: 'RUB',
                'purchased_at' => $p->priced_at?->toDateString(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, QuotePartsPoolItem>  $rows
     * @param  array<string, int>  $stats
     */
    private function syncChunk(Collection $rows, array &$stats): void
    {
        $prices = CatalogSupplierPrice::query()
            ->whereIn('catalog_item_id', $rows->pluck('catalog_item_id'))
            ->whereIn('kind', [CatalogSupplierPrice::KIND_FIRST, CatalogSupplierPrice::KIND_LAST])
            ->get()
            ->groupBy('catalog_item_id');

        $payloads = [];
        $hashes = [];
        $bySku = [];

        foreach ($rows as $row) {
            if ($row->catalogItem === null) {
                continue;
            }
            $payload = $this->payloadFor($row->catalogItem, $prices->get($row->catalog_item_id, collect()));
            $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));

            if ($hash === $row->payload_hash && $row->status !== QuotePartsPoolItem::STATUS_ERROR) {
                $stats['unchanged']++;

                continue;
            }

            $payloads[] = $payload;
            $hashes[$row->sku] = $hash;
            $bySku[$row->sku] = $row;
        }

        if ($payloads === []) {
            return;
        }

        try {
            $results = $this->client->syncItems($payloads);
        } catch (Throwable $e) {
            Log::error('QuoteParts pool sync failed', ['count' => count($payloads), 'error' => $e->getMessage()]);
            foreach ($bySku as $row) {
                $row->forceFill(['status' => QuotePartsPoolItem::STATUS_ERROR, 'last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            }
            $stats['failed'] += count($payloads);

            return;
        }

        foreach ($results as $result) {
            $row = $bySku[$result['code']] ?? null;
            if ($row === null) {
                continue;
            }
            $row->forceFill([
                'status' => $result['status'],
                'remote_version' => $result['version'] ?? null,
                'payload_hash' => $hashes[$result['code']],
                'pause_reasons' => $result['status'] === QuotePartsPoolItem::STATUS_PAUSED
                    ? array_values(array_unique(array_merge((array) $row->pause_reasons, (array) ($result['reasons'] ?? []))))
                    : null,
                'synced_at' => now(),
                'last_error' => null,
            ])->save();
            $stats['sent']++;
            if ($result['status'] === QuotePartsPoolItem::STATUS_PAUSED) {
                $stats['paused']++;
            }
        }
    }
}
