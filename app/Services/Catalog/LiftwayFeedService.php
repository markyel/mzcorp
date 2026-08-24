<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use XMLWriter;

/**
 * Генератор YML-фида «Цены и наличие» для площадки LazyLift/Liftway
 * (см. docs интеграции поставщиков Liftway). MyLift выступает поставщиком:
 * отдаём всю номенклатуру, которую продаём (is_active + есть закупочная цена).
 *
 * Позиции с АКТУАЛЬНОЙ ценой (is_price_actual=true):
 *   <offer id="{sku}" available="true">
 *     <price>…</price><count>{stock}</count>
 *     <param name="ЦенаАктуальна">да</param>        (иначе оффер не покажется)
 *     <param name="СрокПоставки">{lead_time_days}</param>  (если задан, дней)
 *   </offer>
 * Позиции с НЕАКТУАЛЬНОЙ ценой НЕ выкидываем из фида (иначе Liftway не отличит
 * «цена протухла» от «нет в прайсе» и продаёт по старой цене), а помечаем
 * available="false" + count=0 + <param name="ЦенаАктуальна">нет</param>.
 *
 * ЦенаАктуальна теперь ставится ЯВНО (да/нет) — Liftway показывает оффер только
 * при «да». СрокПоставки (под заказ, дней) — per-item из 1С (lead_time_days),
 * пишется когда > 0. Цена = закупка × наценка (config liftway_feed.markup, 1.15).
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
        // Отдаём ВСЕ позиции, которые Liftway у нас продаёт (is_active + есть
        // закупочная цена), включая те, у которых цена сейчас НЕ актуальна.
        // Раньше неактуальные просто выпадали из фида — Liftway не мог отличить
        // «цена протухла» от «позиции нет в прайсе» (фид = подмножество каталога)
        // и продолжал продавать по старой цене. Теперь неактуальные приходят с
        // явным флагом (available=false + param «ЦенаАктуальна»=нет), а когда
        // цена снова актуальна — как обычно (available=true, без флага).
        CatalogItem::query()
            ->where('is_active', true)
            ->where('purchase_price', '>', 0)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->orderBy('id')
            ->select(['id', 'sku', 'purchase_price', 'stock_available', 'is_price_actual', 'lead_time_days'])
            ->chunkById(1000, function ($items) use ($w, $markup, &$count) {
                foreach ($items as $it) {
                    // Последняя известная цена = закупка × наценка (для
                    // неактуальных — тоже она, как «last known»).
                    $price = round(((float) $it->purchase_price) * $markup, 2);
                    if ($price <= 0) {
                        continue;
                    }
                    $priceActual = (bool) $it->is_price_actual;
                    // Срок поставки под заказ (дней) — из 1С «СрокПоставки»
                    // (catalog_items.lead_time_days). 0/NULL = не указан (в наличии).
                    $leadDays = (int) $it->lead_time_days;

                    $w->startElement('offer');
                    $w->writeAttribute('id', (string) $it->sku);
                    $w->writeAttribute('available', $priceActual ? 'true' : 'false');
                    $w->writeElement('price', number_format($price, 2, '.', ''));
                    // Актуальная: фактический остаток (0 = под заказ). Неактуальная:
                    // снята с продажи до обновления цены → count=0.
                    $w->writeElement('count', $priceActual ? (string) max(0, (int) $it->stock_available) : '0');

                    // ЦенаАктуальна — ЯВНО да/нет. Liftway показывает оффер только
                    // при «да» (иначе не отображается); «нет» = цена протухла,
                    // не продавать по старой. Раньше у актуальных флаг опускали
                    // («отсутствие = актуальна») — теперь ставим явно по спеке.
                    $w->startElement('param');
                    $w->writeAttribute('name', 'ЦенаАктуальна');
                    $w->text($priceActual ? 'да' : 'нет');
                    $w->endElement(); // param

                    // СрокПоставки (дней, под заказ) — per-item, только когда задан.
                    if ($leadDays > 0) {
                        $w->startElement('param');
                        $w->writeAttribute('name', 'СрокПоставки');
                        $w->text((string) $leadDays);
                        $w->endElement(); // param
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
