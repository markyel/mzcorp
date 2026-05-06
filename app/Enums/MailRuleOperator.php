<?php

namespace App\Enums;

/**
 * Операторы для критериев правил маршрутизации.
 *
 * contains_any  — поле содержит ХОТЯ БЫ ОДНО из values (case-insensitive substring).
 * not_contains  — поле НЕ содержит ни одно из values.
 * equals_any    — поле точно равно одному из values (case-insensitive).
 * regex_match   — values[0] — регулярное выражение PCRE; matches.
 * ends_with     — заканчивается на одно из values.
 */
enum MailRuleOperator: string
{
    case ContainsAny = 'contains_any';
    case NotContains = 'not_contains';
    case EqualsAny = 'equals_any';
    case RegexMatch = 'regex_match';
    case EndsWith = 'ends_with';

    public function label(): string
    {
        return match ($this) {
            self::ContainsAny => 'содержит',
            self::NotContains => 'не содержит',
            self::EqualsAny => 'равно',
            self::RegexMatch => 'regex',
            self::EndsWith => 'заканчивается на',
        };
    }
}
