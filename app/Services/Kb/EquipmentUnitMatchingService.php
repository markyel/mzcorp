<?php

namespace App\Services\Kb;

use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\RequestContext;
use App\Models\RequestItem;

/**
 * Документ 3 §4.5: привязка позиции к единице оборудования.
 *
 * Используется только если уровень 1 не привязал. Возвращает
 * локальный id (например, "unit_1") или null.
 */
class EquipmentUnitMatchingService
{
    public function match(
        RequestItem $item,
        ?EquipmentCategory $category,
        ?RequestContext $context
    ): ?string {
        // 1) Уровень 1 уже привязал — берём существующее значение
        if ($item->equipment_unit_id !== null && $item->equipment_unit_id !== '') {
            return $item->equipment_unit_id;
        }

        if (!$context || empty($context->equipment_units)) {
            return null;
        }

        $units = $context->equipment_units;

        // 2) Одна единица — привязываем
        if (count($units) === 1) {
            return $units[0]['id'] ?? null;
        }

        // 3) Несколько единиц — фильтр по compatible_equipment категории
        if ($category) {
            $compatible = $category->compatible_equipment ?? [];
            if (is_array($compatible) && !empty($compatible)) {
                $candidates = array_values(array_filter(
                    $units,
                    fn ($u) => is_array($u) && in_array($u['type'] ?? null, $compatible, true)
                ));

                if (count($candidates) === 1) {
                    return $candidates[0]['id'] ?? null;
                }
                // 0 совместимых или >1 — null (документ 3 §4.5.4.2)
            }
        }

        return null;
    }
}
