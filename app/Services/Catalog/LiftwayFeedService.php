<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use XMLWriter;

/**
 * Генератор YML-фида «Цены и наличие» для площадки LazyLift/Liftway
 * (см. docs интеграции поставщиков Liftway). MyLift выступает поставщиком:
 * отдаём номенклатуру, по которой есть АКТУАЛЬНАЯ цена (is_price_actual).
 *
 * Формат минимальный (их парсер читает только offer id/price/count/available):
 *   <offer id="{sku}" available="true"><price>…</price><count>{stock}</count></offer>
 *
 * Цена = закупка × наценка (config services.liftway_feed.markup, дефолт 1.15).
 * Ключ сопоставления = sku (M-артикул) — он же «Ваш код» в прайсе Liftway.
 * Карточку (имя/фото) фид не трогает — она из эталона каталога Liftway.
 */
class LiftwayFeedService
{
    /**
     * @return array{xml: string, count: int, generated_at: string}
     */
    public function generatePricesYml(): array
    {
        $markup = (float) config('services.liftway_feed.markup', 1.15);
        $generatedAt = now()->format('Y-m-d H:i');

        $w = new XMLWriter();
        $w->openMemory();
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('yml_catalog');
        $w->writeAttribute('date', $generatedAt);
        $w->startElement('shop');
        $w->writeElement('name', 'MyZip');
        $w->writeElement('company', 'ООО «Мой Лифт»');
        $w->startElement('offers');

        $count = 0;
        CatalogItem::query()
            ->where('is_active', true)
            ->where('is_price_actual', true)
            ->where('purchase_price', '>', 0)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->orderBy('id')
            ->select(['id', 'sku', 'purchase_price', 'stock_available'])
            ->chunkById(1000, function ($items) use ($w, $markup, &$count) {
                foreach ($items as $it) {
                    $price = round(((float) $it->purchase_price) * $markup, 2);
                    if ($price <= 0) {
                        continue;
                    }
                    $stock = max(0, (int) $it->stock_available);

                    $w->startElement('offer');
                    $w->writeAttribute('id', (string) $it->sku);
                    // Все позиции с актуальной ценой мы можем поставить (в наличии
                    // либо под заказ), поэтому available=true; фактический остаток
                    // передаём в <count> (0 = под заказ).
                    $w->writeAttribute('available', 'true');
                    $w->writeElement('price', number_format($price, 2, '.', ''));
                    $w->writeElement('count', (string) $stock);
                    $w->endElement(); // offer
                    $count++;
                }
            });

        $w->endElement(); // offers
        $w->endElement(); // shop
        $w->endElement(); // yml_catalog
        $w->endDocument();

        return [
            'xml' => $w->outputMemory(),
            'count' => $count,
            'generated_at' => $generatedAt,
        ];
    }
}
