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
     * Специалист по снабжению — раздел «Снабжение»: топ позиций-блокеров
     * выдачи КП, формирование запросов поставщикам по M-артикулу, контроль
     * обновления цен. Не владелец заявок (не в auto-assign).
     */
    case Procurement = 'procurement';
    /**
     * Технический администратор — видит всё (как директорат), но
     * управлять админами могут только другие админы. Не виден в списках
     * менеджеров для РОПа/директора, не назначается на заявки.
     */
    case Admin = 'admin';

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
            self::Procurement => 'Снабжение',
            self::Admin => 'Админ',
        };
    }

    /**
     * Название во множественном числе — для вкладок-фильтров в списке
     * пользователей («Менеджеры», «Секретари»). Отдельно от label(), т.к.
     * там единственное число (подпись роли в карточке).
     */
    public function pluralLabel(): string
    {
        return match ($this) {
            self::Manager => 'Менеджеры',
            self::HeadOfSales => 'РОП',
            self::Secretary => 'Секретари',
            self::Director => 'Директорат',
            self::Procurement => 'Снабжение',
            self::Admin => 'Админы',
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

    /**
     * Роли, по которым в админке есть вкладка-фильтр списка пользователей.
     * Админы не выделены отдельной вкладкой (их видит только другой админ,
     * см. Admin\Managers\Index::users).
     *
     * ЕДИНСТВЕННЫЙ источник набора вкладок и счётчиков — раньше список был
     * захардкожен и в blade, и в counters(), из-за чего роль «Снабжение»
     * не появилась ни там, ни там. См. [[duplicated-source-of-truth]].
     *
     * @return array<int, self>
     */
    public static function userTabRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $r): bool => $r !== self::Admin,
        ));
    }

    /**
     * Роли, которые являются владельцами заявок (request-handler).
     *
     * РОП ведёт заявки наравне с менеджером:
     *  - попадает в round-robin auto-assign,
     *  - доступен в ReassignDialog,
     *  - учитывается в sticky-pool и delegation fallback,
     *  - виден в Dashboard manager-load.
     *
     * @return array<int, string>
     */
    public static function requestHandlerRoles(): array
    {
        return [self::Manager->value, self::HeadOfSales->value];
    }
}
