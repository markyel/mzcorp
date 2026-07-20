<?php

namespace App\Services\HonestSign;

use Smalot\PdfParser\Parser as PdfParser;

/**
 * Разбор PDF с кодами маркировки «Честный знак» (DataMatrix).
 *
 * ВАЖНО про формат исходника (проверено на M05143, 5 страниц):
 *  - **одна страница = один DataMatrix** (один код маркировки);
 *  - под картинкой код напечатан ТЕКСТОМ, но РАЗОРВАН на два фрагмента
 *    (префикс + хвост), между которыми визуально стоит подпись артикула.
 *    «Просто извлечь текст» даёт битую строку вида
 *    `0104681008402919215f8 : M05143 !BGniPQV0JIljDs&R` — части надо сшивать,
 *    а подпись выкидывать. Поэтому парсим ПОСТРАНИЧНО по фрагментам
 *    (`Page::getTextArray()`), а не по сплошному тексту документа.
 *
 * Структура страницы:
 *   [0] 0104681008402919215f8   ← префикс кода
 *   [1] !BGniPQV0JIljDs&R       ← хвост кода
 *   [2..n] слова наименования товара
 *   [n+1] «Артикул: M05143»     ← MZ-ID, по нему связываем со строкой Excel
 *
 * Формат самого кода (GS1 + Честный знак), 38 символов:
 *   `01` + GTIN(14) + `21` + серийный номер и крипто-хвост(20).
 *
 * Декодирование картинок DataMatrix НЕ выполняется: у штатных PDF от
 * поставщика есть текстовый слой. Скан без текстового слоя → parse() вернёт
 * пустой список, вызывающий покажет понятную ошибку (декодер картинок —
 * отдельная задача, если реально появятся сканы).
 */
class HonestSignPdfParser
{
    /** Префикс кода: 01 + GTIN(14) + 21 + начало серийника. */
    private const CODE_PREFIX_RE = '/^01\d{14}21\S*$/u';

    /** Ожидаемая полная длина кода маркировки. */
    public const CODE_LENGTH = 38;

    /**
     * @return array{
     *   codes: array<int, array{code: string, gtin: string, serial: string, article: ?string, name: ?string, page: int}>,
     *   pages: int,
     *   warnings: array<int, string>
     * }
     */
    public function parse(string $absolutePath): array
    {
        $codes = [];
        $warnings = [];

        try {
            $pages = (new PdfParser())->parseFile($absolutePath)->getPages();
        } catch (\Throwable $e) {
            return [
                'codes' => [],
                'pages' => 0,
                'warnings' => ['Не удалось прочитать PDF: ' . $e->getMessage()],
            ];
        }

        foreach ($pages as $index => $page) {
            $pageNo = $index + 1;

            try {
                $fragments = array_values(array_filter(
                    array_map('trim', $page->getTextArray()),
                    static fn ($t) => $t !== '',
                ));
            } catch (\Throwable $e) {
                $warnings[] = "Стр. {$pageNo}: не удалось извлечь текст ({$e->getMessage()}).";
                continue;
            }

            $parsed = $this->parsePageFragments($fragments, $pageNo, $warnings);
            if ($parsed !== null) {
                $codes[] = $parsed;
            }
        }

        return ['codes' => $codes, 'pages' => count($pages), 'warnings' => $warnings];
    }

    /**
     * @param  array<int, string>  $fragments
     * @param  array<int, string>  $warnings
     * @return array{code: string, gtin: string, serial: string, article: ?string, name: ?string, page: int}|null
     */
    private function parsePageFragments(array $fragments, int $pageNo, array &$warnings): ?array
    {
        $prefixIdx = null;
        foreach ($fragments as $i => $f) {
            if (preg_match(self::CODE_PREFIX_RE, $f) === 1) {
                $prefixIdx = $i;
                break;
            }
        }

        if ($prefixIdx === null) {
            // Страница без текстового слоя кода (скан) либо служебная.
            $warnings[] = "Стр. {$pageNo}: код маркировки не найден в текстовом слое.";

            return null;
        }

        $prefix = $fragments[$prefixIdx];
        $suffix = '';
        // Хвост — следующий фрагмент, если он не является сам началом кода
        // и не служебной подписью (артикул/наименование идут после).
        $next = $fragments[$prefixIdx + 1] ?? '';
        if ($next !== ''
            && preg_match(self::CODE_PREFIX_RE, $next) !== 1
            && ! preg_match('/^Артикул\s*:/ui', $next)
        ) {
            $suffix = $next;
        }

        $code = $prefix . $suffix;

        if (! preg_match('/^01(\d{14})21(.+)$/u', $code, $m)) {
            $warnings[] = "Стр. {$pageNo}: код не соответствует формату 01+GTIN+21 — пропущен.";

            return null;
        }
        if (mb_strlen($code) !== self::CODE_LENGTH) {
            // Не фатально (бывают иные длины крипто-хвоста), но помечаем.
            $warnings[] = sprintf(
                'Стр. %d: длина кода %d вместо ожидаемых %d — проверьте вручную.',
                $pageNo, mb_strlen($code), self::CODE_LENGTH,
            );
        }

        $article = null;
        $nameParts = [];
        foreach ($fragments as $i => $f) {
            if ($i <= $prefixIdx + ($suffix !== '' ? 1 : 0)) {
                continue;
            }
            if (preg_match('/^Артикул\s*:\s*(\S+)/ui', $f, $am)) {
                $article = $am[1];
                continue;
            }
            $nameParts[] = $f;
        }

        return [
            'code' => $code,
            'gtin' => $m[1],
            'serial' => $m[2],
            'article' => $article,
            // Имя в PDF разбито по словам с переносами — склеиваем.
            'name' => $nameParts !== [] ? preg_replace('/\s+/u', ' ', implode(' ', $nameParts)) : null,
            'page' => $pageNo,
        ];
    }

    /**
     * Коды, сгруппированные по артикулу (MZ-ID) — под заполнение Excel.
     * Дубликаты внутри артикула схлопываются с сохранением порядка страниц.
     *
     * @param  array<int, array{code: string, gtin: string, article: ?string}>  $codes
     * @return array<string, array{gtin: string, codes: array<int, string>}>
     */
    public function groupByArticle(array $codes): array
    {
        $grouped = [];
        foreach ($codes as $c) {
            $key = (string) ($c['article'] ?? '');
            if ($key === '') {
                continue;
            }
            if (! isset($grouped[$key])) {
                $grouped[$key] = ['gtin' => $c['gtin'], 'codes' => []];
            }
            if (! in_array($c['code'], $grouped[$key]['codes'], true)) {
                $grouped[$key]['codes'][] = $c['code'];
            }
        }

        return $grouped;
    }

    /**
     * Значение ячейки КИЗ: коды через `;` + перенос строки, у последнего
     * разделителя нет (формат сверен с эталоном заказчика).
     *
     * @param  array<int, string>  $codes
     */
    public function formatKizCell(array $codes): string
    {
        return implode(";\n", $codes);
    }
}
