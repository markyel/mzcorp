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

    /**
     * YML-фид «Поставки в пути»: позиции, которые заказаны и едут
     * (catalog_items.stock_in_transit = [{qty,date}]). На витрине — «В пути ·
     * прибудет ДД.ММ». Снапшот: позиция есть в фиде → в пути; убрали → снялось.
     *
     * Одна позиция = один <offer>: count = сумма БУДУЩИХ партий, ДатаПоступления
     * = ближайшая дата прихода (у позиции бывает несколько партий, а формат
     * несёт одну дату). Прошедшие даты (уже должны быть на складе) отбрасываем.
     *
     * @return array{xml: string, count: int, generated_at: string}
     */
    public function generateInTransitYml(): array
    {
        $today = now()->toDateString(); // 'Y-m-d' — сравнение строк = сравнение дат
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
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->whereNotNull('stock_in_transit')
            ->where('stock_in_transit', '!=', '[]')
            ->orderBy('id')
            ->select(['id', 'sku', 'stock_in_transit'])
            ->chunkById(1000, function ($items) use ($w, &$count, $today) {
                foreach ($items as $it) {
                    $batches = is_array($it->stock_in_transit) ? $it->stock_in_transit : [];
                    $totalQty = 0;
                    $earliest = null;
                    foreach ($batches as $b) {
                        $qty = (int) ($b['qty'] ?? 0);
                        $date = trim((string) ($b['date'] ?? ''));
                        if ($qty <= 0 || $date === '' || $date < $today) {
                            continue; // пустая/прошедшая партия — уже должна быть на складе
                        }
                        $totalQty += $qty;
                        if ($earliest === null || $date < $earliest) {
                            $earliest = $date;
                        }
                    }
                    if ($totalQty <= 0) {
                        continue;
                    }

                    $w->startElement('offer');
                    $w->writeAttribute('id', (string) $it->sku);
                    $w->writeElement('count', (string) $totalQty);
                    if ($earliest !== null) {
                        $w->startElement('param');
                        $w->writeAttribute('name', 'ДатаПоступления');
                        $w->text($earliest);
                        $w->endElement();
                    }
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
