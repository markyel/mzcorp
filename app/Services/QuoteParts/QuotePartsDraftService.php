<?php

namespace App\Services\QuoteParts;

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
}
