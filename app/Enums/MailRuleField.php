<?php

namespace App\Enums;

/**
 * Поля письма, против которых проверяются критерии правил.
 *
 * Phase 1 минимум по Foundation §1.5.
 */
enum MailRuleField: string
{
    case Subject = 'subject';
    case FromEmail = 'from_email';
    case FromDomain = 'from_domain';
    case Body = 'body';

    public function label(): string
    {
        return match ($this) {
            self::Subject => 'Тема',
            self::FromEmail => 'Email отправителя',
            self::FromDomain => 'Домен отправителя',
            self::Body => 'Тело письма',
        };
    }
}
