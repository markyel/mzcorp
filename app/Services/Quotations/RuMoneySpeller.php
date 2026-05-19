<?php

namespace App\Services\Quotations;

/**
 * Преобразование суммы в рублях в строку прописью на русском.
 *   1047088.52 → «Один миллион сорок семь тысяч восемьдесят восемь рублей 52 копейки»
 *
 * Реализация без внешних deps. Достаточно для шапки PDF КП:
 *  - целая часть прописью со склонением «рубль/рубля/рублей»
 *  - копейки числом, со склонением «копейка/копейки/копеек»
 *  - первая буква капитализируется
 *
 * Если потребуется больше языков / валют — drop-in morphos/morphos
 * (https://github.com/wapmorgan/Morphos) и убрать этот класс.
 */
class RuMoneySpeller
{
    private const UNITS_M = [
        '', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять',
    ];
    private const UNITS_F = [
        '', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять',
    ];
    private const TEENS = [
        'десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать',
        'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать',
    ];
    private const TENS = [
        '', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят',
        'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто',
    ];
    private const HUNDREDS = [
        '', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот',
        'шестьсот', 'семьсот', 'восемьсот', 'девятьсот',
    ];

    /**
     * @param  float  $amount  Полная сумма (например, 1047088.52).
     */
    public function spell(float $amount): string
    {
        $amount = round($amount, 2);
        $rubles = (int) floor($amount);
        $kopecks = (int) round(($amount - $rubles) * 100);

        $words = $rubles === 0
            ? 'ноль'
            : $this->triplets($rubles);
        $words .= ' ' . $this->pluralRu($rubles, 'рубль', 'рубля', 'рублей');
        $words .= ' ' . sprintf('%02d', $kopecks)
            . ' ' . $this->pluralRu($kopecks, 'копейка', 'копейки', 'копеек');

        return mb_strtoupper(mb_substr($words, 0, 1)) . mb_substr($words, 1);
    }

    /**
     * Развёртка числа на тройки разрядов: миллионы → тысячи → единицы.
     */
    private function triplets(int $n): string
    {
        if ($n < 0) {
            return 'минус ' . $this->triplets(-$n);
        }
        $parts = [];

        $bil = intdiv($n, 1_000_000_000);
        if ($bil > 0) {
            $parts[] = $this->triplet($bil, masculine: true)
                . ' ' . $this->pluralRu($bil, 'миллиард', 'миллиарда', 'миллиардов');
            $n -= $bil * 1_000_000_000;
        }
        $mln = intdiv($n, 1_000_000);
        if ($mln > 0) {
            $parts[] = $this->triplet($mln, masculine: true)
                . ' ' . $this->pluralRu($mln, 'миллион', 'миллиона', 'миллионов');
            $n -= $mln * 1_000_000;
        }
        $thd = intdiv($n, 1_000);
        if ($thd > 0) {
            // Тысячи в женском роде («одна тысяча», «две тысячи»)
            $parts[] = $this->triplet($thd, masculine: false)
                . ' ' . $this->pluralRu($thd, 'тысяча', 'тысячи', 'тысяч');
            $n -= $thd * 1_000;
        }
        if ($n > 0) {
            $parts[] = $this->triplet($n, masculine: true);
        }

        return implode(' ', $parts);
    }

    private function triplet(int $n, bool $masculine): string
    {
        if ($n <= 0 || $n >= 1000) {
            return '';
        }
        $h = intdiv($n, 100);
        $rest = $n % 100;
        $out = self::HUNDREDS[$h];

        if ($rest >= 10 && $rest <= 19) {
            $out = trim($out . ' ' . self::TEENS[$rest - 10]);
        } else {
            $t = intdiv($rest, 10);
            $u = $rest % 10;
            $out = trim($out . ' ' . self::TENS[$t]);
            if ($u > 0) {
                $out = trim($out . ' ' . ($masculine ? self::UNITS_M[$u] : self::UNITS_F[$u]));
            }
        }

        return $out;
    }

    /**
     * Склонение русских существительных по числу.
     */
    private function pluralRu(int $n, string $one, string $few, string $many): string
    {
        $mod10 = abs($n) % 10;
        $mod100 = abs($n) % 100;
        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }
        if ($mod10 === 1) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4) {
            return $few;
        }

        return $many;
    }
}
