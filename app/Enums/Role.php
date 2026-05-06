<?php

namespace App\Enums;

/**
 * Роли пользователей MyLift.
 *
 * Соответствуют ролям из Foundation §«Роли и права доступа».
 * Используются как имена ролей в spatie/laravel-permission.
 */
enum Role: string
{
    case Manager = 'manager';
    case HeadOfSales = 'head_of_sales';
    case Secretary = 'secretary';
    case Director = 'director';

    /**
     * Локализованное название роли (для UI).
     */
    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Менеджер',
            self::HeadOfSales => 'РОП',
            self::Secretary => 'Секретарь',
            self::Director => 'Директорат',
        };
    }

    /**
     * Все роли в виде массива значений (строк).
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
