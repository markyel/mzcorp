<?php

namespace App\Enums;

/**
 * На чём держится склейка заявок-близнецов (AssignmentService::matchGround).
 * Пишется в reason назначения (`auto_twin:{"ground":…}`) — РОП видит в карточке,
 * почему заявки сочтены одним спросом, и по журналу можно считать ложные склейки.
 *
 * Порядок cases — от надёжного к слабому: основание всей заявки — самое
 * слабое из оснований её позиций.
 */
enum TwinGround: string
{
    /** Один и тот же код в обоих письмах (артикул клиента). */
    case OwnArticle = 'own_article';

    /** Обе позиции привязаны к одному товару каталога. */
    case Catalog = 'catalog';

    /** Код письма совпал с артикулом товара каталога, к которому привязана другая позиция. */
    case CatalogCode = 'catalog_code';

    /** Одинаковое название. */
    case Name = 'name';

    public function label(): string
    {
        return match ($this) {
            self::OwnArticle => 'один артикул в обоих письмах',
            self::Catalog => 'один товар каталога',
            self::CatalogCode => 'артикул товара каталога',
            self::Name => 'одинаковое название',
        };
    }

    /** Слабее ли это основание, чем $other. */
    public function weakerThan(self $other): bool
    {
        $order = array_flip(array_map(fn (self $g) => $g->value, self::cases()));

        return $order[$this->value] > $order[$other->value];
    }
}
