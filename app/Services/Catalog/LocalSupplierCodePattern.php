<?php

namespace App\Services\Catalog;

/**
 * Распознавание «локальных» (поставщических/клиентских) кодов товара,
 * которые НЕ являются настоящими OEM-артикулами производителя и не
 * должны блокировать article_safety check / B-step matching.
 *
 * Примеры:
 *   - LW-0027349 (внутренний код поставщика OTIS-запчастей; реальный
 *     OEM-артикул той же позиции — DAA332N2 или GAA50AHA1-6M)
 *   - LW-0005540, LW-0015952, LW-0000024
 *
 * Принцип: если client прислал ТОЛЬКО локальные коды (без настоящих
 * OEM-токенов), для алгоритмов это эквивалентно «артикула нет»:
 *   - matchByArticle (B-step) пропускает такие токены — в каталоге их
 *     заведомо нет;
 *   - matchByRequestItem (C-step) безопасно ищет по name, не блокируется
 *     article_safety;
 *   - buildQueryText не подмешивает локальный код в embed-вектор (он
 *     добавляет шум, ни одной каталожной позиции с LW-... не существует).
 *
 * Если хоть один токен — реальный OEM (TAA346ADH22, GAA50AHA1-6M),
 * считаем артикул значимым (см. isAllLocal — false).
 *
 * Расширяется добавлением паттернов в PATTERNS. Каждый паттерн
 * применяется к токену ПОСЛЕ normalizeArticle (uppercase + strip
 * [\s\-_./]), т.е. `LW-0027349` → `LW0027349`.
 */
final class LocalSupplierCodePattern
{
    /**
     * Список regex'ов локальных кодов. Применяется к нормализованному
     * (uppercase, без разделителей) токену целиком.
     *
     * @var list<string>
     */
    private const PATTERNS = [
        // LW-XXXX...  — внутренний код поставщика OTIS-запчастей.
        // После normalizeArticle: «LW-0027349» → «LW0027349».
        '/^LW\d{4,}$/',
    ];

    /**
     * Этот один токен — локальный код?
     */
    public static function isLocalToken(string $token): bool
    {
        $norm = CatalogImportService::normalizeArticle($token);
        if ($norm === null || $norm === '') {
            return false;
        }
        foreach (self::PATTERNS as $rx) {
            if (preg_match($rx, $norm) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Все ли comma-split токены в строке — локальные коды? Если да,
     * для алгоритмов матчинга это эквивалентно «реального артикула нет».
     *
     * Пустой / null article тоже возвращает true (нечего сравнивать).
     */
    public static function isAllLocal(?string $article): bool
    {
        if ($article === null || trim($article) === '') {
            return true;
        }
        $tokens = preg_split('/\s*[,\/]\s*/', $article) ?: [$article];
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '') {
                continue;
            }
            if (! self::isLocalToken($tok)) {
                return false; // нашли хоть один реальный OEM-токен
            }
        }
        return true;
    }
}
