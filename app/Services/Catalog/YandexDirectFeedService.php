<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use XMLWriter;

/**
 * YML-фид для товарной кампании Яндекс.Директа.
 *
 * В фид попадают ТОЛЬКО позиции, по которым мы способны ответить клиенту сразу:
 * есть остаток на складе И цена актуальна (is_price_actual). Это тот же
 * критерий, по которому заявка с явным артикулом уходит в автоматическое КП, —
 * реклама приводит клиента ровно на такой товар, а не на «уточните наличие».
 *
 * Не путать с LiftwayFeedService: тот отдаёт партнёрской площадке ВСЮ
 * номенклатуру (включая протухшие цены, с явным флагом) по закупке с наценкой.
 * Здесь — витрина для рекламы: розничная цена, ссылка на карточку, фото.
 *
 * Требования Директа к офферу: id, url, price, currencyId, categoryId, picture,
 * name. Ссылка ведёт на карточку mylift.ru по M-артикулу и несёт utm-метки,
 * чтобы трафик кампании отличался в Метрике.
 */
class YandexDirectFeedService
{
    /**
     * @return array{xml: string, count: int, generated_at: string}
     */
    public function generate(): array
    {
        $generatedAt = now()->format('Y-m-d H:i');

        $items = CatalogItem::query()
            ->where('is_active', true)
            ->where('stock_available', '>', 0)
            ->where('price', '>', 0)
            ->where('is_price_actual', true)
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->orderBy('id')
            ->get([
                'id', 'sku', 'name', 'part_type', 'brand', 'brand_article', 'articles',
                'price', 'stock_available', 'lead_time_days', 'photo_url', 'description',
                'weight', 'unit_name',
            ]);

        // Категории — из уникальных part_type, id генерируем сами: фид
        // самодостаточен, внешнего справочника категорий у нас нет.
        $categoryIds = [];
        foreach ($items as $it) {
            $cat = trim((string) $it->part_type);
            if ($cat !== '' && ! isset($categoryIds[$cat])) {
                $categoryIds[$cat] = count($categoryIds) + 1;
            }
        }

        $w = new XMLWriter;
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('yml_catalog');
        $w->writeAttribute('date', $generatedAt);
        $w->startElement('shop');
        $w->writeElement('name', 'Мой ЗиП');
        $w->writeElement('company', 'ООО «Мой Лифт»');
        $w->writeElement('url', (string) config('services.yandex_direct.feed.shop_url', 'https://myzip.ru'));

        $w->startElement('currencies');
        $w->startElement('currency');
        $w->writeAttribute('id', 'RUR');
        $w->writeAttribute('rate', '1');
        $w->endElement();
        $w->endElement();

        $w->startElement('categories');
        foreach ($categoryIds as $name => $cid) {
            $w->startElement('category');
            $w->writeAttribute('id', (string) $cid);
            $w->text($name);
            $w->endElement();
        }
        $w->endElement();

        $w->startElement('offers');

        $count = 0;
        foreach ($items as $it) {
            $price = round((float) $it->price, 2);
            if ($price <= 0) {
                continue;
            }

            $w->startElement('offer');
            $w->writeAttribute('id', (string) $it->sku);
            $w->writeAttribute('available', 'true');

            $w->writeElement('url', self::productUrl((string) $it->sku));
            $w->writeElement('price', number_format($price, 2, '.', ''));
            $w->writeElement('currencyId', 'RUR');

            $cat = trim((string) $it->part_type);
            if ($cat !== '' && isset($categoryIds[$cat])) {
                $w->writeElement('categoryId', (string) $categoryIds[$cat]);
            }
            if (trim((string) $it->photo_url) !== '') {
                $w->writeElement('picture', (string) $it->photo_url);
            }

            $w->writeElement('name', self::offerName($it));
            if (trim((string) $it->brand) !== '') {
                $w->writeElement('vendor', (string) $it->brand);
            }
            $w->writeElement('vendorCode', (string) $it->sku);
            if (trim((string) $it->brand_article) !== '') {
                $w->writeElement('model', (string) $it->brand_article);
            }
            $w->writeElement('count', (string) max(0, (int) $it->stock_available));

            $description = trim((string) $it->description);
            if ($description !== '') {
                $w->writeElement('description', mb_substr($description, 0, 3000));
            }

            // Коды производителей — по ним же строятся ключевые фразы кампании.
            $codes = self::codes($it);
            if ($codes !== []) {
                $this->param($w, 'Артикулы производителя', implode(', ', $codes));
            }
            $this->param($w, 'Наличие', 'на складе');
            // lead_time_days НЕ выводим: у позиций в наличии он означает срок
            // поставки СЛЕДУЮЩЕЙ партии (у 980 из 2435 это 60+ дней) и рядом
            // с «на складе» читается как противоречие.
            if ((float) $it->weight > 0) {
                $this->param($w, 'Вес, кг', (string) round((float) $it->weight, 3));
            }
            // unit_name в каталоге хранит не единицу измерения, а узел лифта,
            // к которому относится деталь, — так и подписываем.
            if (trim((string) $it->unit_name) !== '') {
                $this->param($w, 'Узел', mb_substr((string) $it->unit_name, 0, 200));
            }

            $w->endElement(); // offer
            $count++;
        }

        $w->endElement(); // offers
        $w->endElement(); // shop
        $w->endElement(); // yml_catalog
        $w->endDocument();

        return ['xml' => $w->outputMemory(), 'count' => $count, 'generated_at' => $generatedAt];
    }

    /** Ссылка на карточку товара с метками — чтобы трафик кампании был виден в Метрике. */
    public static function productUrl(string $sku): string
    {
        $pattern = (string) config(
            'services.yandex_direct.feed.product_url',
            'https://www.mylift.ru/ru/?com=shop&srv=product&code={sku}',
        );
        $url = str_replace('{sku}', rawurlencode($sku), $pattern);

        $utm = array_filter([
            'utm_source' => config('services.yandex_direct.feed.utm_source', 'yandex'),
            'utm_medium' => config('services.yandex_direct.feed.utm_medium', 'cpc'),
            'utm_campaign' => config('services.yandex_direct.feed.utm_campaign', 'direct_products'),
            'utm_content' => $sku,
        ]);
        if ($utm === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($utm);
    }

    /**
     * Название оффера: имя каталога, а если его нет — собираем из типа, бренда
     * и артикула, чтобы оффер не остался безымянным.
     */
    public static function offerName(object $item): string
    {
        $name = trim((string) ($item->name ?? ''));
        if ($name === '') {
            $name = trim(implode(' ', array_filter([
                (string) ($item->part_type ?? ''),
                (string) ($item->brand ?? ''),
                (string) ($item->brand_article ?? ''),
            ])));
        }

        return mb_substr($name !== '' ? $name : (string) ($item->sku ?? ''), 0, 255);
    }

    /**
     * Коды производителя позиции (articles + brand_article), нормализованные и
     * без дублей. Наш собственный M-артикул отбрасываем: в `articles` он лежит
     * у 648 позиций из 2435, а «артикулом производителя» не является.
     *
     * @return array<int, string>
     */
    public static function codes(object $item): array
    {
        $ownSku = mb_strtoupper(trim((string) ($item->sku ?? '')));
        $raw = $item->articles ?? [];
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }
        $raw = is_array($raw) ? $raw : [];
        if (trim((string) ($item->brand_article ?? '')) !== '') {
            $raw[] = $item->brand_article;
        }

        $out = [];
        foreach ($raw as $code) {
            $code = trim((string) $code);
            if ($code === '' || mb_strlen($code) > 60) {
                continue;
            }
            $upper = mb_strtoupper($code);
            if ($ownSku !== '' && $upper === $ownSku) {
                continue;
            }
            // Побеждает ПЕРВОЕ написание: в articles код приведён к виду
            // производителя, а brand_article нередко записан как придётся.
            $out[mb_strtoupper($code)] ??= $code;
        }

        return array_values($out);
    }

    private function param(XMLWriter $w, string $name, string $value): void
    {
        if (trim($value) === '') {
            return;
        }
        $w->startElement('param');
        $w->writeAttribute('name', $name);
        $w->text($value);
        $w->endElement();
    }
}
