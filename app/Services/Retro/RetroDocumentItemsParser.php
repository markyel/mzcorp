<?php

namespace App\Services\Retro;

/**
 * Позиции из текста наших КП и счетов из 1С (pdftotext -layout), без GPT.
 *
 * Таблица у обоих шаблонов: строка позиции начинается с номера, наименование может
 * переноситься на следующие строки (они начинаются в той же колонке), в строке есть
 * артикул каталога «M01234» (в счёте — в конце наименования), количество с единицей
 * и суммы в конце. В КП после количества идут цена, скидка %, цена со скидкой, сумма
 * и НДС, в счёте — цена и сумма. Перед таблицей в КП есть «Содержание запроса» —
 * он не разбирается: таблица начинается после заголовка с «Наименование» или «Товар».
 *
 * Проверка: сумма строк должна совпасть с «Итого со скидкой» (иначе «Итого») с точностью
 * до рубля — 1С округляет скидку по документу.
 * Не совпала — документ не разобран, позиции не отдаются.
 */
class RetroDocumentItemsParser
{
    private const MONEY = '(?<![\d,.])\d{1,3}(?:[ \x{00A0}]\d{3})*,\d{2}(?!\d)';

    private const UNITS = 'шт|штук|компл|комплект|к-т|кмп|м|мп|пог\.?\s?м|п\.м|кг|г|л|пар|пара|уп|упак|рул|набор|ед|м2|м²|м3|лист|бухта|катушка';

    /**
     * @return array{items: list<array{position:int, name:string, article:?string, qty:?float, unit:?string,
     *     price:?float, discount:?float, price_discounted:?float, sum:float}>, total:?float, sum:float, ok:bool}
     */
    public static function parse(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $start = null;
        $end = null;
        foreach ($lines as $i => $line) {
            if ($start === null && preg_match('/(?:Наименование,\s*характеристика|^\s*№\s+Товар\b|\bТовар\s{2,}.*Кол-во)/u', $line)) {
                $start = $i + 1;
            }
            if ($start !== null && $i >= $start && preg_match('/\bИтого:/u', $line)) {
                $end = $i;
                break;
            }
        }
        $total = self::total($lines);
        // Шаблон КП: после суммы строки идёт колонка НДС, заголовок с «Цена со скидкой».
        $withVat = $start !== null && preg_match('/скидкой|в т\. ч\./u', implode("\n", array_slice($lines, max(0, $start - 1), 10)));
        if ($start === null || $end === null) {
            return ['items' => [], 'total' => $total, 'sum' => 0.0, 'ok' => false];
        }

        // Строки с суммами, строки с номером позиции (номер у левого края) и строки колонки
        // наименования (без сумм, с отступом колонки; в ней же справа бывают «Гарантировано» /
        // «до …» — берём только первый кусок).
        $moneyLines = [];
        $indexLines = [];
        $nameLines = [];
        $order = [];
        for ($i = $start; $i < $end; $i++) {
            $line = rtrim($lines[$i]);
            if (trim($line) === '' || preg_match('/^\s*(Примечание|Итого|Скидка|В том числе|№\s|при выставлении)/u', $line) || str_contains($line, 'Наименование')) {
                continue;
            }
            $order[] = $i;
            $hasMoney = preg_match_all('/'.self::MONEY.'/u', $line) >= 2;
            if (preg_match('/^\s{0,2}(\d{1,3})(?:\s+(.*))?$/u', $line, $m)) {
                $indexLines[$i] = ['position' => (int) $m[1], 'text' => (string) ($m[2] ?? '')];
            } else {
                $pos = mb_strlen($line) - mb_strlen(ltrim($line));
                if (! $hasMoney && $pos >= 3 && $pos <= 14) {
                    $nameLines[$i] = trim(preg_split('/\s{2,}/u', trim($line))[0]);
                }
            }
            if ($hasMoney) {
                $moneyLines[$i] = ltrim($line);
            }
        }
        if ($moneyLines === []) {
            return ['items' => [], 'total' => $total, 'sum' => 0.0, 'ok' => false];
        }

        // Строка позиции: суммы и номер. Номер стоит на той же строке или, если наименование
        // в три строки и больше, на соседней — рядом со средней частью наименования. Центр
        // ячейки наименования — строка с номером.
        $rows = [];
        $paired = [];
        foreach (array_keys($moneyLines) as $line) {
            $at = array_search($line, $order, true);
            $center = null;
            foreach ([$line, $order[$at + 1] ?? null, $order[$at - 1] ?? null] as $candidate) {
                if ($candidate !== null && isset($indexLines[$candidate]) && ! isset($paired[$candidate])
                    && ($candidate === $line || ! isset($moneyLines[$candidate]))) {
                    $center = $candidate;
                    break;
                }
            }
            $center ??= $line;
            $paired[$center] = true;
            if ($center === $line) {
                $text = isset($indexLines[$line]) ? $indexLines[$line]['text'] : $moneyLines[$line];
                $name = preg_match('/^(?:[MМ]\d{4,6}\b|\d)/u', $text) ? ''
                    : (preg_match('/^(.*?)\s{2,}(?=[MМ]\d{4,6}\b|\d)/u', $text, $m) ? $m[1] : $text);
                $tail = mb_substr($text, mb_strlen($name));
            } else {
                $name = trim(preg_split('/\s{2,}/u', trim($indexLines[$center]['text']))[0]);
                $tail = $moneyLines[$line];
            }
            $rows[$center] = ['position' => $indexLines[$center]['position'] ?? count($rows) + 1, 'name' => $name, 'tail' => $tail];
        }
        ksort($rows);

        // Строки наименования между позициями. В КП ячейка центрирована по высоте: часть над
        // строкой с номером, часть под ней, поровну. В счёте — по верху: всё под строкой.
        $centers = array_keys($rows);
        $between = [];
        foreach ($centers as $k => $center) {
            $from = $k === 0 ? $start : $centers[$k - 1];
            $between[$k] = array_values(array_filter($nameLines, fn ($i) => $i > $from && $i < $center, ARRAY_FILTER_USE_KEY));
        }
        $after = array_values(array_filter($nameLines, fn ($i) => $i > end($centers), ARRAY_FILTER_USE_KEY));
        $centered = $between[0] !== [] || array_filter($rows, fn ($r) => $r['name'] === '') !== []
            || array_diff($centers, array_keys($moneyLines)) !== [];

        $items = [];
        $above = [];
        foreach ($centers as $k => $center) {
            $next = $between[$k + 1] ?? $after;
            if ($centered) {
                $above = $k === 0 ? $between[0] : array_slice($between[$k], count($above));
                $below = array_slice($next, 0, count($above));
            } else {
                $above = [];
                $below = $next;
            }
            $items[] = self::finish($rows[$center] + ['above' => $above, 'more' => $below], (bool) $withVat);
        }


        $sum = round(array_sum(array_column($items, 'sum')), 2);
        $ok = $items !== [] && $total !== null && abs($sum - $total) <= max(0.02 + 0.01 * count($items), 1.0);

        return ['items' => $ok ? $items : [], 'total' => $total, 'sum' => $sum, 'ok' => $ok];
    }

    private static function finish(array $row, bool $withVat = false): array
    {
        $name = $row['name'];
        $tail = $row['tail'];
        $name = trim((string) preg_replace('/\s+/u', ' ', implode(' ', array_merge($row['above'] ?? [], [$name], $row['more']))));

        $article = null;
        if (preg_match('/(?<![\w-])([MМ])(\d{4,6})(?!\w)/u', $tail, $a) || preg_match('/(?<![\w-])([MМ])(\d{4,6})(?!\w)/u', $name, $a)) {
            $article = 'M'.$a[2];
            $name = trim((string) preg_replace('/[,\s]*(?<![\w-])[MМ]'.$a[2].'(?!\w)\s*$/u', '', $name));
        }

        $qty = null;
        $unit = null;
        $afterQty = $tail;
        if (preg_match_all('/(\d{1,3}(?:[ \x{00A0}]\d{3})*(?:,\d{1,3})?)\s+('.self::UNITS.')\.?(?=\s|$)/u', $tail, $q, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $last = end($q);
            $qty = self::number($last[1][0]);
            $unit = $last[2][0];
            $afterQty = substr($tail, $last[0][1] + strlen($last[0][0]));
        }
        preg_match_all('/'.self::MONEY.'/u', $afterQty, $money);
        $values = array_map([self::class, 'number'], $money[0]);
        // КП: цена, [скидка], цена со скидкой, сумма, НДС — сумма предпоследняя. Счёт: цена, сумма.
        [$price, $discount, $discounted, $sum] = $withVat && count($values) >= 3 ? [
            $values[0], count($values) >= 5 ? $values[1] : null, $values[count($values) - 3], $values[count($values) - 2],
        ] : match (true) {
            count($values) >= 5 => [$values[0], $values[1], $values[2], $values[count($values) - 2]],
            count($values) === 4 => [$values[0], $values[1], $values[2], $values[3]],
            count($values) === 3 => [$values[0], null, $values[1], $values[2]],
            count($values) === 2 => [$values[0], null, null, $values[1]],
            count($values) === 1 => [null, null, null, $values[0]],
            default => [null, null, null, 0.0],
        };

        return [
            'position' => $row['position'], 'name' => $name, 'article' => $article, 'qty' => $qty, 'unit' => $unit,
            'price' => $price, 'discount' => $discount, 'price_discounted' => $discounted, 'sum' => (float) $sum,
        ];
    }

    private static function total(array $lines): ?float
    {
        $plain = null;
        foreach ($lines as $line) {
            if (preg_match('/Итого со скидкой:\s*('.self::MONEY.')/u', $line, $m)) {
                return self::number($m[1]);
            }
            if ($plain === null && preg_match('/\bИтого:\s*('.self::MONEY.')/u', $line, $m)) {
                $plain = self::number($m[1]);
            }
        }

        return $plain;
    }

    private static function number(string $value): float
    {
        return (float) str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $value);
    }
}
