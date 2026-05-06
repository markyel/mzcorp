<?php

namespace App\Enums;

/**
 * Класс письма по версии AI-классификатора (Foundation §2.4).
 *
 *   request          — заявка клиента на запчасти / RFQ.
 *   reclamation      — рекламация / претензия / возврат / брак.
 *   accounting       — бухгалтерия (счета, акты, УПД, оплаты).
 *   general_question — общий вопрос (info), не заявка.
 *   spam             — спам / маркетинговая рассылка.
 *   other            — все, что не подходит ни под один класс выше.
 */
enum EmailClassification: string
{
    case Request = 'request';
    case Reclamation = 'reclamation';
    case Accounting = 'accounting';
    case GeneralQuestion = 'general_question';
    case Spam = 'spam';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Request => 'Заявка',
            self::Reclamation => 'Рекламация',
            self::Accounting => 'Бухгалтерия',
            self::GeneralQuestion => 'Общий вопрос',
            self::Spam => 'Спам',
            self::Other => 'Прочее',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
