<?php

namespace App\Enums;

/**
 * Статус качества/готовности позиции заявки (Phase 2.0 + Priority 1).
 *
 * Заполняется QualityAssessmentService → может быть промотирован каталогом
 * (CatalogResolutionService A-step), либо вручную оператором
 * (RequestItemEditor::markCatalogNotFound, refreshFromCatalog).
 *
 * CHECK constraint в БД:
 *   request_items_quality_assessment_status_check
 * Миграции:
 *   2026_05_08_200012 — исходный enum (5 значений)
 *   2026_05_08_210001 — + internal_catalog_pending (6 значений)
 *   2026_05_14_130000 — + internal_catalog_not_found (7 значений)
 */
enum QualityAssessmentStatus: string
{
    case NotAssessed = 'not_assessed';
    case Sufficient = 'sufficient';
    case Insufficient = 'insufficient';
    case NotCovered = 'not_covered';
    case AssessmentFailed = 'assessment_failed';
    case InternalCatalogPending = 'internal_catalog_pending';
    case InternalCatalogNotFound = 'internal_catalog_not_found';

    /**
     * UI-надпись на чипе в карточке заявки.
     */
    public function label(): string
    {
        return match ($this) {
            self::NotAssessed => 'не оценено',
            self::Sufficient => 'данных достаточно',
            self::Insufficient => 'данных мало',
            self::NotCovered => 'нет правил',
            self::AssessmentFailed => 'ошибка KB',
            self::InternalCatalogPending => 'внутренний SKU · ждёт каталог',
            self::InternalCatalogNotFound => 'нет в каталоге',
        };
    }

    /**
     * CSS-класс чипа в design tokens (`chip-ok / chip-attn / chip-over / chip-neutral / chip-info / chip-danger`).
     */
    public function chipClass(): string
    {
        return match ($this) {
            self::Sufficient => 'chip-ok',
            self::Insufficient => 'chip-attn',
            self::NotCovered => 'chip-neutral',
            self::AssessmentFailed => 'chip-over',
            self::InternalCatalogPending => 'chip-info',
            self::InternalCatalogNotFound => 'chip-danger',
            self::NotAssessed => 'chip-neutral',
        };
    }

    /**
     * Является ли статус «терминальным с точки зрения каталога» —
     * ResolvePendingFromCatalogJob его НЕ должен трогать.
     */
    public function isCatalogTerminal(): bool
    {
        return in_array($this, [
            self::Sufficient,
            self::InternalCatalogNotFound,
        ], true);
    }
}
