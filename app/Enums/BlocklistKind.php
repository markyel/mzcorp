<?php

namespace App\Enums;

/**
 * Вид записи стоп-листа отправителей.
 *
 * spam     — настоящий спам/нерелевантное: письма ОТБРАСЫВАЮТСЯ (category=
 *            irrelevant), не читаются, заявок не создают.
 * supplier — пул поставщика: письма НЕ создают клиентских заявок, НО
 *            ПРОЧИТЫВАЮТСЯ как переписка с поставщиком (category=supplier_reply,
 *            прикрепляются к SupplierInquiry, видны в /dashboard/suppliers).
 *            Заводится при закрытии заявки причиной «Переписка с поставщиком»
 *            с галкой «занести в стоп-лист». См. [[suppliers-module]].
 */
enum BlocklistKind: string
{
    case Spam = 'spam';
    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Спам',
            self::Supplier => 'Поставщик',
        };
    }
}
