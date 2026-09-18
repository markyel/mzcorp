<?php

namespace App\Services\Request;

/**
 * Нормализация артикулов позиций для сравнения «та же вещь» между заявками.
 *
 * Одну и ту же позицию клиенты пишут по-разному: «MLKAT-X (VER-1)» у клиента
 * напрямую и «MLKAT-X VER-1» в заявке с площадки. Точное сравнение строк такие
 * пары не ловит, и заявки расходятся по разным менеджерам (кейс M-2026-16404 /
 * M-2026-16406 от 18.09.2026).
 *
 * Правило то же, что в аналитике каталога: верхний регистр, кириллические
 * двойники → латиница, прочь всё, кроме букв и цифр.
 */
class ItemTokenizer
{
    /** Кириллица, неотличимая от латиницы на вид. */
    private const LOOKALIKE_FROM = 'МАВСЕКНОРТХУ';

    private const LOOKALIKE_TO = 'MABCEKHOPTXY';

    /** Токен короче — слишком общий, матчить по нему опасно. */
    public const MIN_TOKEN_LENGTH = 5;

    /** Больше токенов в sticky-запрос не отдаём — по одному LIKE на токен. */
    public const MAX_TOKENS = 8;

    /** То же выражение на стороне Postgres — для сравнения в SQL. */
    public static function sqlNormalize(string $column): string
    {
        return sprintf(
            "regexp_replace(upper(translate(coalesce(%s, ''), '%s', '%s')), '[^A-Z0-9]', '', 'g')",
            $column,
            self::LOOKALIKE_FROM,
            self::LOOKALIKE_TO,
        );
    }

    public static function normalize(?string $value): string
    {
        $upper = mb_strtoupper(trim((string) $value));
        $latin = strtr($upper, array_combine(
            mb_str_split(self::LOOKALIKE_FROM),
            mb_str_split(self::LOOKALIKE_TO),
        ));

        return preg_replace('/[^A-Z0-9]/u', '', $latin) ?? '';
    }

    /** Единицы измерения — хвост размерного токена, а не артикула. */
    private const UNITS = 'MM|CM|M|KG|G|MG|L|ML|V|W|A|HZ|N|NM|PCS|SHT';

    /**
     * Годится ли токен для матчинга.
     *
     * Годятся артикулы: «CAN1X», «MLKATXVER1», «ZAA717AP1», наш «M05186».
     * Не годятся размеры и количества — «800MM», «L100MM», «15», «2026»:
     * по ним связывались заведомо разные заявки (вкладыш L100мм и совсем
     * другой вкладыш той же длины), это выяснилось на сухом прогоне.
     */
    public static function isDistinctive(string $token): bool
    {
        // Наш каталожный артикул — сильный сигнал даже с одной буквой.
        if (preg_match('/^M\d{4,6}$/', $token) === 1) {
            return true;
        }
        // Число с единицей измерения или без: 800MM, 15, 2026.
        if (preg_match('/^\d+(?:'.self::UNITS.')?$/', $token) === 1) {
            return false;
        }
        // Размер с буквенным префиксом: L100MM, D15MM, H40CM.
        if (preg_match('/^[A-Z]{1,2}\d{1,4}(?:'.self::UNITS.')$/', $token) === 1) {
            return false;
        }

        return mb_strlen($token) >= self::MIN_TOKEN_LENGTH
            && preg_match('/\d/', $token) === 1
            && preg_match_all('/[A-Z]/', $token) >= 2;
    }

    /**
     * Токены заявки: нормализованные артикулы позиций плюс артикулоподобные
     * куски из названий (клиент часто пишет всё одной строкой, и артикул
     * второй позиции оказывается внутри названия первой).
     *
     * @param  iterable<object>  $items  позиции с parsed_article / parsed_name
     * @return array<int, string>
     */
    public static function tokensFor(iterable $items): array
    {
        $tokens = [];

        foreach ($items as $item) {
            $article = self::normalize($item->parsed_article ?? null);
            if (self::isDistinctive($article)) {
                $tokens[$article] = true;
            }

            foreach (preg_split('/[\s,;()\[\]"\']+/u', (string) ($item->parsed_name ?? '')) ?: [] as $word) {
                $token = self::normalize($word);
                if (self::isDistinctive($token)) {
                    $tokens[$token] = true;
                }
            }
        }

        return array_slice(array_keys($tokens), 0, self::MAX_TOKENS);
    }
}
