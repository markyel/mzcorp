<?php

namespace App\Enums;

/**
 * Группа товара по месту закупки (по поставщикам из «Закупок 1С»): китайским поставщикам —
 * то, что везём из Китая, российским — то, что берём в России, европейским — то, что
 * импортируем только из Европы. Код же — регион поставщика 1С.
 */
enum SupplyGroup: string
{
    case China = 'cn';
    case Russia = 'ru';
    case Europe = 'eu';

    public function label(): string
    {
        return match ($this) {
            self::China => 'Китай',
            self::Russia => 'Россия',
            self::Europe => 'Европа',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
