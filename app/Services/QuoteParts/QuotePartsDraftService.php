<?php

namespace App\Services\QuoteParts;

use App\Models\CatalogSupplierPrice;
use App\Models\RequestItem;
use App\Services\Supplier\SupplierItemTranslator;
use RuntimeException;

/**
 * Новый товар без M-артикула (сценарий 1): позиция заявки уходит в QuoteParts
 * предварительной позицией с временным кодом T-XXXXXX. Код запоминается в позиции
 * заявки (request_items.quoteparts_code): повторный запрос по ней идёт с тем же кодом,
 * и все ответы поставщиков копятся на одной карточке. Название и характеристики —
 * по-английски для поставщика (SupplierItemTranslator), русское название — для нас.
 */
class QuotePartsDraftService
{
    public function __construct(
        private readonly QuotePartsClient $client,
        private readonly SupplierItemTranslator $translator,
    ) {}

    /**
     * @param  list<int>  $requestItemIds
     * @return array<int, string> request_item_id => код в QuoteParts
     */
    public function ensureDrafts(array $requestItemIds): array
    {
        $rows = RequestItem::query()->with('request:id,internal_code')->whereIn('id', $requestItemIds)->whereNull('catalog_item_id')->get();
        if ($rows->count() !== count(array_unique($requestItemIds))) {
            throw new RuntimeException('Предварительной позицией уходит только товар без M-артикула.');
        }
        if ($rows->contains(fn ($r) => trim((string) $r->parsed_name) === '')) {
            throw new RuntimeException('У позиции нет названия — заполните его в заявке.');
        }

        $names = $this->translator->translate($rows->mapWithKeys(fn ($r) => [$r->id => (string) $r->parsed_name])->all());

        $result = $this->client->createDrafts($rows->map(fn (RequestItem $r) => [
            'ref' => 'ri:'.$r->id,
            'name' => (string) $r->parsed_name,
            'name_en' => $names[$r->id] ?? null,
            'brand' => $r->parsed_brand ?: null,
            'mpn' => $r->parsed_article ?: null,
            'unit' => $r->parsed_unit ?: null,
            'specs' => array_filter([
                'Length' => $r->parsed_length ? rtrim(rtrim(number_format((float) $r->parsed_length, 3, '.', ''), '0'), '.').' '.($r->parsed_length_unit ?: '') : null,
            ]),
            'note' => 'Заявка '.($r->request?->internal_code ?? '#'.$r->request_id),
        ])->values()->all());

        $codes = [];
        foreach ($result as $row) {
            $id = (int) substr((string) ($row['ref'] ?? ''), 3);
            if ($id > 0 && ! empty($row['code'])) {
                $codes[$id] = (string) $row['code'];
                RequestItem::query()->whereKey($id)->update(['quoteparts_code' => $row['code']]);
            }
        }
        if (count($codes) !== $rows->count()) {
            throw new RuntimeException('QuoteParts не завёл часть предварительных позиций.');
        }

        return $codes;
    }

    /**
     * Позиции заявок, ушедшие предварительными и теперь сопоставленные с каталогом (у товара
     * появился M-артикул из корп. базы): в QuoteParts предварительная позиция становится
     * постоянной — офферы, вопросы и ответы остаются с ней, — и попадает в пул.
     *
     * @return int сколько переведено
     */
    public function promoteMatched(QuotePartsPoolService $pool, int $limit = 100): int
    {
        $rows = RequestItem::query()
            ->whereNotNull('quoteparts_code')->whereNotNull('catalog_item_id')->whereNull('quoteparts_promoted_at')
            ->with('catalogItem')->orderBy('id')->limit($limit)->get()
            ->filter(fn ($r) => $r->catalogItem !== null && str_starts_with((string) $r->quoteparts_code, 'T-'));
        if ($rows->isEmpty()) {
            return 0;
        }

        $prices = CatalogSupplierPrice::query()
            ->whereIn('catalog_item_id', $rows->pluck('catalog_item_id'))
            ->whereIn('kind', [CatalogSupplierPrice::KIND_FIRST, CatalogSupplierPrice::KIND_LAST])
            ->get()->groupBy('catalog_item_id');

        // Одна предварительная позиция — одна строка, даже если её код стоит у нескольких позиций.
        $byCode = $rows->unique('quoteparts_code');
        $results = $this->client->promoteDrafts($byCode->map(fn (RequestItem $r) => ['draft_code' => $r->quoteparts_code]
            + $pool->payloadFor($r->catalogItem, $prices->get($r->catalog_item_id, collect())))->values()->all());

        $done = collect($results)->filter(fn ($r) => in_array($r['result'] ?? null, ['promoted', 'merged', 'already', 'unknown'], true))->pluck('draft_code')->all();
        RequestItem::query()->whereIn('quoteparts_code', $done)->whereNotNull('catalog_item_id')->update(['quoteparts_promoted_at' => now()]);

        // Дальше позиция живёт в пуле и обновляется вместе с каталогом.
        $promotedCatalogIds = $rows->whereIn('quoteparts_code', $done)->pluck('catalog_item_id')->unique()->values()->all();
        if ($promotedCatalogIds !== []) {
            $pool->ensureInPool($promotedCatalogIds, null);
        }

        return count($done);
    }
}
