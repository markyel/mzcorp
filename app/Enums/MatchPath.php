<?php

namespace App\Enums;

/**
 * «Как пришла позиция» — снимок результата автомата-резолвера.
 *
 * Присваивается при первом резолве (CatalogResolutionService::matchOrResolve)
 * и читается из `request_items.match_path`. Это **snapshot входной сложности**
 * — после ручной правки менеджером (linkToCatalog) НЕ меняется, потому что
 * нас интересует «в каком виде заявка пришла к менеджеру», а не итоговое
 * состояние после его работы.
 *
 * Соответствие `quality_assessment_payload.catalog_match.method`:
 *   - A_internal_sku  → MatchPath::InternalSku   (вес 1, easy)
 *   - B_brand_article → MatchPath::BrandArticle  (вес 2, минимальная проверка)
 *   - C_name_vector   → MatchPath::NameMatch     (вес 3, нужна проверка LLM)
 *   - manual_link     → MatchPath::Manual        (вес 8, менеджер сам разбирался)
 *   - null / status ∈ {insufficient, not_covered, internal_catalog_not_found}
 *                     → MatchPath::Manual        (требует ручной работы)
 */
enum MatchPath: string
{
    case InternalSku = 'internal_sku';
    case BrandArticle = 'brand_article';
    case NameMatch = 'name_match';
    case Manual = 'manual';

    /**
     * Локализованный label для UI (chip / kvgrid / dashboard breakdown).
     */
    public function label(): string
    {
        return match ($this) {
            self::InternalSku => 'M-артикул',
            self::BrandArticle => 'OEM-код',
            self::NameMatch => 'По названию',
            self::Manual => 'Требует разбора',
        };
    }

    /**
     * Иконка для chip / table column.
     */
    public function icon(): string
    {
        return match ($this) {
            self::InternalSku => '🟢',
            self::BrandArticle => '🔵',
            self::NameMatch => '🟡',
            self::Manual => '🔴',
        };
    }

    /**
     * Базовый вес позиции в complexity_score. Можно переопределить через
     * AppSetting (см. RequestComplexityService::weights()).
     */
    public function defaultWeight(): int
    {
        return match ($this) {
            self::InternalSku => 1,
            self::BrandArticle => 2,
            self::NameMatch => 3,
            self::Manual => 8,
        };
    }

    /**
     * Tailwind chip-class для UI индикации. Совпадает с tone'ами из
     * других enum'ов (QualityAssessmentStatus, AttentionReason).
     */
    public function chipClass(): string
    {
        return match ($this) {
            self::InternalSku => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::BrandArticle => 'bg-sky-50 text-sky-700 border-sky-200',
            self::NameMatch => 'bg-amber-50 text-amber-700 border-amber-200',
            self::Manual => 'bg-red-50 text-red-700 border-red-200',
        };
    }

    /**
     * Определить MatchPath по данным позиции.
     *
     * Принцип «оцениваем что клиент прислал». Priority order:
     *
     *   (1) **Override**: если parsed_article ИЛИ parsed_name содержит M-SKU
     *       pattern (`[MМm]\d{4,}`) — это всегда `internal_sku`, независимо
     *       от того что записал автомат-резолвер. Покрывает кейс когда
     *       парсер не вытащил M-артикул в parsed_article, и catalog_match
     *       сработал через B_brand_article (нашёл по совпадению с brand_article
     *       в каталоге) — семантически клиент прислал M-артикул.
     *
     *   (2) Если автомат-резолвер успешно сматчил (payload.catalog_match.method
     *       заполнен) — берём прямой результат A/B/C/manual_link.
     *
     *   (3) Fallback по статусу: internal_catalog_pending/not_found → internal_sku.
     *
     *   (4) Fallback по parsed_article: что-то есть → brand_article;
     *       пусто → manual.
     *
     * @param  array<string, mixed>|null  $payload  quality_assessment_payload
     * @param  ?int  $catalogItemId  request_items.catalog_item_id
     * @param  ?string  $status  quality_assessment_status (raw enum value)
     * @param  ?string  $parsedArticle  request_items.parsed_article
     * @param  ?string  $parsedName  request_items.parsed_name
     */
    public static function detect(
        ?array $payload,
        ?int $catalogItemId,
        ?string $status,
        ?string $parsedArticle = null,
        ?string $parsedName = null,
    ): self {
        // (1a) HARD OVERRIDE: M-SKU pattern в article ИЛИ name. Клиент явно
        //      прислал M-артикул — это всегда internal_sku, независимо от
        //      того, как именно автомат потом его сматчил в каталоге.
        if (is_string($parsedArticle) && self::looksLikeInternalSku($parsedArticle)) {
            return self::InternalSku;
        }
        if (is_string($parsedName) && self::looksLikeInternalSku($parsedName)) {
            return self::InternalSku;
        }

        // (1b) HARD OVERRIDE: catalog matcher нашёл M-SKU через B_brand_article.
        //      Кейс M-2026-1192: клиент в письме явно написал «M00828 — 60 шт»,
        //      но парсер ПОДМЕНИЛ parsed_name на каталожное имя
        //      («Ролик ступени 506 NCE...») при successful match. M-артикул
        //      из исходного текста стёрся, но catalog matcher через B нашёл
        //      MyZip-каталожную позицию (cat_sku=M\d+) — клиент явно дал M-арт.
        //
        //      НЕ применяется к C_name_vector — там клиент дал описание,
        //      vector нашёл M-SKU semantic-search'ом, это не «клиент прислал M-арт».
        $method = $payload['catalog_match']['method'] ?? null;
        $catalogSku = $payload['catalog_match']['catalog_sku'] ?? null;
        if ($method === 'B_brand_article'
            && is_string($catalogSku)
            && self::looksLikeInternalSku($catalogSku)) {
            return self::InternalSku;
        }

        // (2) Автомат-резолвер успешно сматчил → берём прямой результат.
        $matched = match ($method) {
            'A_internal_sku' => self::InternalSku,
            'B_brand_article' => self::BrandArticle,
            'C_name_vector' => self::NameMatch,
            'manual_link' => self::Manual,
            default => null,
        };
        if ($matched !== null) {
            return $matched;
        }

        // (3) Fallback по статусу: парсер пометил позицию как M-SKU
        //     (через QualityAssessmentService::detectInternalCatalogSku).
        if ($status === 'internal_catalog_pending'
            || $status === 'internal_catalog_not_found') {
            return self::InternalSku;
        }

        // (4) Fallback по parsed_article.
        if (is_string($parsedArticle) && trim($parsedArticle) !== '') {
            return self::BrandArticle;
        }

        return self::Manual;
    }

    /**
     * Эвристика «это M-SKU MyZip?» — латинская M или кириллическая М
     * + минимум 4 цифры, обрамлённые не-буквенными границами.
     * Те же правила что в QualityAssessmentService::detectInternalCatalogSku.
     */
    private static function looksLikeInternalSku(string $value): bool
    {
        return (bool) preg_match(
            '/(?<![\p{L}\p{N}_])[MМm]\d{4,}(?![\p{L}\p{N}_])/u',
            $value,
        );
    }

    /** @return array<int, string> Значения для CHECK-constraint миграции. */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
