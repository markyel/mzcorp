<?php

namespace App\Support;

/**
 * Номер заявки в письмах. Два формата: прежний «M-2026-21748» и с перезапуска после аварии
 * «MZ2026-00499» (решение заказчика 05.11.2026: новые заявки продолжают ряд заявок за дни аварии).
 * Все места, где номер ищется в теме или тексте письма, берут шаблон отсюда — иначе ответ
 * клиента на письмо с новым номером не нашёл бы свою заявку.
 */
final class RequestCode
{
    /** Без ограничителей и флагов — для вставки в другие выражения. */
    public const PATTERN = '(?:M-\d{4}-\d{1,6}|MZ\d{4}-\d{5})';

    public const REGEX = '/\b'.self::PATTERN.'\b/iu';

    /** Первый номер заявки в тексте, в том виде, как он хранится (верхний регистр). */
    public static function find(string $text): ?string
    {
        return preg_match(self::REGEX, $text, $m) === 1 ? mb_strtoupper($m[0]) : null;
    }

    /** @return list<string> все номера заявок в тексте, без повторов */
    public static function all(string $text): array
    {
        preg_match_all(self::REGEX, $text, $m);

        return array_values(array_unique(array_map('mb_strtoupper', $m[0] ?? [])));
    }

    public static function is(string $code): bool
    {
        return preg_match('/^'.self::PATTERN.'$/iu', $code) === 1;
    }
}
