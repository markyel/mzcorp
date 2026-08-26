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
 * при «да». СрокПоставки (под заказ, РАБОЧИХ дней) — per-item: 1С хранит срок в
 * календарных днях (lead_time_days, недели×7), конвертируем ×5/7 в рабочие;
 * актуальным под-заказным (в наличии 0) без срока в 1С ставим дефолт
 * liftway_feed.default_lead_work_days (50). Цена = закупка × наценка (markup 1.15).
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
        // Дефолтный срок (раб.дней) для актуальных под-заказных без срока в 1С.
        $defaultLeadWork = (int) config('services.liftway_feed.default_lead_work_days', 50);
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
            ->chunkById(1000, function ($items) use ($w, $markup, $defaultLeadWork, &$count) {
                foreach ($items as $it) {
                    // Последняя известная цена = закупка × наценка (для
                    // неактуальных — тоже она, как «last known»).
                    $price = round(((float) $it->purchase_price) * $markup, 2);
                    if ($price <= 0) {
                        continue;
                    }
                    $priceActual = (bool) $it->is_price_actual;
                    $stock = max(0, (int) $it->stock_available);

                    // Срок поставки. В 1С «СрокПоставки» (lead_time_days) хранится в
                    // КАЛЕНДАРНЫХ днях (недели×7: 70=10нед, 84=12нед…), а Liftway
                    // ждёт РАБОЧИЕ дни → конвертируем ×5/7 (70→50, 14→10). Для
                    // актуальных под-заказных (в наличии 0) без срока в 1С — дефолт
                    // default_lead_work_days (раб.дней). Пишем СрокПоставки только >0.
                    $leadCal = (int) $it->lead_time_days;
                    if ($leadCal > 0) {
                        $leadWork = max(1, (int) round($leadCal * 5 / 7));
                    } elseif ($priceActual && $stock <= 0) {
                        $leadWork = $defaultLeadWork;
                    } else {
                        $leadWork = 0;
                    }

                    $w->startElement('offer');
                    $w->writeAttribute('id', (string) $it->sku);
                    $w->writeAttribute('available', $priceActual ? 'true' : 'false');
                    $w->writeElement('price', number_format($price, 2, '.', ''));
                    // Актуальная: фактический остаток (0 = под заказ). Неактуальная:
                    // снята с продажи до обновления цены → count=0.
                    $w->writeElement('count', $priceActual ? (string) $stock : '0');

                    // ЦенаАктуальна — ЯВНО да/нет. Liftway показывает оффер только
                    // при «да» (иначе не отображается); «нет» = цена протухла,
                    // не продавать по старой. Раньше у актуальных флаг опускали
                    // («отсутствие = актуальна») — теперь ставим явно по спеке.
                    $w->startElement('param');
                    $w->writeAttribute('name', 'ЦенаАктуальна');
                    $w->text($priceActual ? 'да' : 'нет');
                    $w->endElement(); // param

                    // СрокПоставки (РАБОЧИХ дней, под заказ) — per-item, только >0.
                    if ($leadWork > 0) {
                        $w->startElement('param');
                        $w->writeAttribute('name', 'СрокПоставки');
                        $w->text((string) $leadWork);
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
     * ТЕСТОВЫЙ YML-фид с ПОЛНОЙ карточкой товара (name/бренды/OEM/категория/
     * фото/габариты/вес/цена/наличие/срок), в отличие от prices.yml (только
     * sku+price+count). Лимит позиций — config liftway_feed.full_limit (тест=100).
     * Цена = закупка×markup (как в prices.yml, без себестоимости наружу).
     * Категории строятся из part_type (YML требует <categories> + categoryId).
     *
     * @return array{xml: string, count: int, generated_at: string}
     */
    public function generateFullYml(): array
    {
        $markup = (float) config('services.liftway_feed.markup', 1.15);
        $limit = (int) config('services.liftway_feed.full_limit', 100);
        $generatedAt = now()->format('Y-m-d H:i');

        $items = CatalogItem::query()
            ->where('is_active', true)
            ->where('purchase_price', '>', 0)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->orderBy('id')
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->get([
                'id', 'sku', 'name', 'name_en', 'unit_name', 'part_type', 'brand',
                'brand_article', 'brands', 'articles', 'form_factor', 'placement',
                'size_a', 'size_b', 'size_c', 'size_d', 'size_e', 'size_f', 'weight',
                'price', 'purchase_price', 'stock_available', 'is_price_actual',
                'lead_time_days', 'photo_url', 'description',
            ]);

        // Категории из уникальных part_type → синтетический id (self-contained).
        $categoryIds = [];
        foreach ($items as $it) {
            $cat = trim((string) $it->part_type);
            if ($cat !== '' && ! isset($categoryIds[$cat])) {
                $categoryIds[$cat] = count($categoryIds) + 1;
            }
        }

        $w = new XMLWriter();
        $w->openMemory();
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('yml_catalog');
        $w->writeAttribute('date', $generatedAt);
        $w->startElement('shop');
        $w->writeElement('name', 'MyZip');
        $w->writeElement('company', 'ООО «Мой Лифт»');

        $w->startElement('currencies');
        $w->startElement('currency');
        $w->writeAttribute('id', 'RUR');
        $w->writeAttribute('rate', '1');
        $w->endElement(); // currency
        $w->endElement(); // currencies

        $w->startElement('categories');
        foreach ($categoryIds as $name => $cid) {
            $w->startElement('category');
            $w->writeAttribute('id', (string) $cid);
            $w->text($name);
            $w->endElement();
        }
        $w->endElement(); // categories

        $w->startElement('offers');

        $count = 0;
        foreach ($items as $it) {
            $price = round(((float) $it->purchase_price) * $markup, 2);
            if ($price <= 0) {
                continue;
            }
            $priceActual = (bool) $it->is_price_actual;
            $stock = max(0, (int) $it->stock_available);
            $leadCal = (int) $it->lead_time_days;
            $leadWork = $leadCal > 0 ? max(1, (int) round($leadCal * 5 / 7)) : 0;

            $brands = $this->decodeList($it->brands);
            $articles = $this->decodeList($it->articles);

            $w->startElement('offer');
            $w->writeAttribute('id', (string) $it->sku);
            $w->writeAttribute('available', $priceActual ? 'true' : 'false');

            $w->writeElement('name', (string) ($it->name ?: $it->sku));
            if (trim((string) $it->name_en) !== '') {
                $w->writeElement('name_en', (string) $it->name_en);
            }
            if (trim((string) $it->brand) !== '') {
                $w->writeElement('vendor', (string) $it->brand);
            }
            $w->writeElement('vendorCode', (string) $it->sku);
            if (trim((string) $it->brand_article) !== '') {
                $w->writeElement('model', (string) $it->brand_article);
            }
            $w->writeElement('price', number_format($price, 2, '.', ''));
            $w->writeElement('currencyId', 'RUR');
            $cat = trim((string) $it->part_type);
            if ($cat !== '' && isset($categoryIds[$cat])) {
                $w->writeElement('categoryId', (string) $categoryIds[$cat]);
            }
            if (trim((string) $it->photo_url) !== '') {
                $w->writeElement('picture', (string) $it->photo_url);
            }
            $w->writeElement('count', (string) ($priceActual ? $stock : 0));
            if (trim((string) $it->description) !== '') {
                $w->writeElement('description', mb_substr((string) $it->description, 0, 3000));
            }

            // Полные атрибуты через <param>.
            $this->param($w, 'Артикул (Ваш код)', $it->sku);
            $this->param($w, 'Категория', $it->part_type);
            $this->param($w, 'Бренд', $it->brand);
            if ($brands !== []) {
                $this->param($w, 'Бренды (все)', implode(', ', $brands));
            }
            $this->param($w, 'Артикул бренда', $it->brand_article);
            if ($articles !== []) {
                $this->param($w, 'OEM / кросс-номера', implode(', ', array_slice($articles, 0, 30)));
            }
            $this->param($w, 'Размещение', $it->placement);
            // Числовые характеристики — только ненулевые (0.000 не отдаём).
            $this->paramNum($w, 'Вес, кг', $it->weight);
            $this->paramNum($w, 'Габарит A', $it->size_a);
            $this->paramNum($w, 'Габарит B', $it->size_b);
            $this->paramNum($w, 'Габарит C', $it->size_c);
            $this->paramNum($w, 'Габарит D', $it->size_d);
            $this->paramNum($w, 'Габарит E', $it->size_e);
            $this->paramNum($w, 'Габарит F', $it->size_f);
            if ($leadWork > 0) {
                $this->param($w, 'СрокПоставки', (string) $leadWork);
            }
            $this->param($w, 'ЦенаАктуальна', $priceActual ? 'да' : 'нет');

            $w->endElement(); // offer
            $count++;
        }

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

    /** Написать <param name="…">value</param>, только для непустого значения. */
    private function param(XMLWriter $w, string $name, mixed $value): void
    {
        $value = trim((string) $value);
        if ($value === '') {
            return;
        }
        $w->startElement('param');
        $w->writeAttribute('name', $name);
        $w->text($value);
        $w->endElement();
    }

    /** Числовой <param>: пропускаем пустое И нулевое (0 / 0.000). */
    private function paramNum(XMLWriter $w, string $name, mixed $value): void
    {
        if ($value === null || (is_numeric($value) && (float) $value == 0.0)) {
            return;
        }
        // Убираем хвостовые нули: 12.500 → 12.5, 240.000 → 240.
        $num = (float) $value;
        $this->param($w, $name, rtrim(rtrim(number_format($num, 3, '.', ''), '0'), '.'));
    }

    /**
     * Декод jsonb-списка (brands/articles) в плоский массив непустых строк.
     *
     * @return list<string>
     */
    private function decodeList(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $v) {
            $v = trim((string) $v);
            if ($v !== '' && ! in_array($v, $out, true)) {
                $out[] = $v;
            }
        }

        return $out;
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
