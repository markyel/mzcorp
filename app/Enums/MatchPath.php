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
     * Определить MatchPath по `quality_assessment_payload` позиции.
     * Не требует RequestItem — принимает array, чтобы можно было дёрнуть
     * из CLI / Service без эх'ов модели.
     *
     * @param  array<string, mixed>|null  $payload  quality_assessment_payload
     * @param  ?int  $catalogItemId  request_items.catalog_item_id
     * @param  ?string  $status  quality_assessment_status (raw enum value)
     */
    public static function detect(?array $payload, ?int $catalogItemId, ?string $status): self
    {
        $method = $payload['catalog_match']['method'] ?? null;
        return match ($method) {
            'A_internal_sku' => self::InternalSku,
            'B_brand_article' => self::BrandArticle,
            'C_name_vector' => self::NameMatch,
            'manual_link' => self::Manual,
            default => self::Manual,
        };
    }

    /** @return array<int, string> Значения для CHECK-constraint миграции. */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
