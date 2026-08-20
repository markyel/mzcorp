<?php

namespace App\Support;

/**
 * Единый источник правды: что считается нашим собственным house-brand
 * («Мой ЗиП» / «Мой Лифт» / mzCorp). Такой бренд не должен попадать в
 * parsed_brand КЛИЕНТСКОЙ позиции — это не OEM-бренд запрошенного товара,
 * а маркетинговое имя нашего каталога. Пример: клиент прислал фото
 * контактного моста без бренда; позиция сматчилась на house-brand товар
 * каталога (у 752 позиций каталога brand='Мой ЗиП'), и applyCatalogToItem
 * скопировал «Мой ЗиП» в parsed_brand → в карточке заявки бренд выглядит
 * взявшимся из ниоткуда (кейс ri#24363, заявка M-2026-11668).
 *
 * Используется и парсером (RequestItemPersister), и каталожным матчером
 * (CatalogResolutionService::applyCatalogToItem) — общий фильтр, чтобы не
 * плодить дубль списка house-brand'ов (см. CLAUDE.md «Дубль источника правды»).
 */
final class HouseBrand
{
    /** Нормализованные формы наших house-brand'ов. */
    private const NAMES = ['мойзип', 'myzip', 'мойлифт', 'mylift', 'ооомойлифт', 'ооомойзип', 'mzcorp'];

    /** Нормализация: lower + без пробелов/точек/дефисов/кавычек. */
    private static function normalize(?string $brand): string
    {
        return mb_strtolower(preg_replace('/[\s.\-«»"]+/u', '', (string) $brand) ?? '');
    }

    public static function is(?string $brand): bool
    {
        $norm = self::normalize($brand);

        return $norm !== '' && in_array($norm, self::NAMES, true);
    }

    /**
     * Вернуть бренд как есть, либо null если это наш house-brand / пусто.
     */
    public static function filter(?string $brand): ?string
    {
        $brand = trim((string) $brand);

        return ($brand === '' || self::is($brand)) ? null : $brand;
    }
}
