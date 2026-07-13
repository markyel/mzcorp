<?php

namespace App\Enums;

/**
 * Режим расчёта цены для покупателя (Organization::pricing_mode).
 *
 *   standard  — обычное ценообразование: каталожная цена минус скидка,
 *               с полом price_min (см. QuotationService::computeFinalUnitPrice).
 *   cost_plus — спец-режим «Себестоимость + наценка»: цена = закупочная
 *               (catalog purchase_price) × (1 + markup/100), БЕЗ пола price_min
 *               и БЕЗ дополнительных скидок. Наценка — глобальная,
 *               config('services.pricing.cost_plus_markup'). Применяется к КП
 *               и счетам покупателя. См. QuotationService::recalcTotals.
 */
enum OrganizationPricingMode: string
{
    case Standard = 'standard';
    case CostPlus = 'cost_plus';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Стандартный (каталог − скидка)',
            self::CostPlus => 'Себестоимость + наценка',
        };
    }

    public function isCostPlus(): bool
    {
        return $this === self::CostPlus;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }
}
