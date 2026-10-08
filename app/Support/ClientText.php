<?php

namespace App\Support;

/**
 * Мелочи русского текста в письмах клиентам (авто-уведомления, авто-КП).
 *
 *  - personName(): имя для обращения «Здравствуйте, …!» — только если поле
 *    «От» похоже на имя человека. Компании, отделы, адреса и мусор
 *    («Liftway.ru — [ЗАКУПКИ]», «СП Евролифт», «info») → null, и письмо
 *    начинается просто с «Здравствуйте!».
 *  - days(): «1 день / 3 дня / 7 дней» вместо «7 дн.».
 *  - percent(): «12,5%» — запятая, без хвостовых нулей.
 *  - listItem(): пункт Markdown-списка с экранированием разметки.
 */
class ClientText
{
    /** Слова, по которым «имя» — на самом деле организация, отдел или роль. */
    private const NOT_A_PERSON = [
        'ооо', 'оао', 'зао', 'пао', 'ао', 'ип', 'тсж', 'тсн', 'жск', 'ук', 'гк', 'нпо', 'нпп', 'сп', 'чп', 'пк',
        'мкп', 'мп', 'гуп', 'муп', 'фгуп', 'гбу', 'мбу', 'фгбу', 'llc', 'ltd', 'inc', 'co', 'corp', 'gmbh', 'group',
        'company', 'компания', 'группа', 'отдел', 'закупки', 'закупок', 'снабжение', 'снабжения', 'бухгалтерия',
        'продажи', 'продаж', 'sales', 'info', 'office', 'admin', 'support', 'service', 'сервис', 'лифт', 'лифты',
        'лифтов', 'лифтовая', 'lift', 'lifts', 'elevator', 'elevators', 'escalator', 'team', 'manager', 'менеджер',
        'order', 'orders', 'zakaz', 'zakupki', 'snab', 'mail', 'почта', 'ремонт', 'монтаж', 'служба', 'центр',
        'завод', 'магазин', 'склад', 'диспетчер', 'диспетчерская', 'администратор', 'администрация', 'управление',
        'клиент', 'client', 'customer', 'noreply', 'no-reply', 'robot', 'бот',
    ];

    /** Окончания отчества: «Николай Николаевич», «Ольга Петровна». */
    private const PATRONYMIC = '/(вич|вна|ична|инична|ич)$/u';

    /**
     * Имя для обращения или null, если это не похоже на человека.
     */
    public static function personName(?string $raw): ?string
    {
        $name = trim((string) $raw);
        // Кавычки и скобки вокруг имени из заголовка From: "Alexey Alpatsky", «Иван».
        $name = trim($name, " \t\"'«»“”„()");
        if ($name === '' || mb_strlen($name) > 60) {
            return null;
        }
        // Адреса, сайты, цифры, скобки, тире-разделители — это не имя человека.
        if (preg_match('/[@\d\[\]{}<>|\/\\\\.,;:!?#_+=—–]/u', $name)) {
            return null;
        }

        $words = preg_split('/\s+/u', $name) ?: [];
        if ($words === [] || count($words) > 3) {
            return null;
        }
        foreach ($words as $w) {
            // Только буквы одного слова (допустим дефис и апостроф: Анна-Мария, O'Neil).
            if (! preg_match("/^\\p{L}+(?:[-']\\p{L}+)*$/u", $w)) {
                return null;
            }
            if (in_array(mb_strtolower($w), self::NOT_A_PERSON, true)) {
                return null;
            }
        }
        if (count($words) === 1 && mb_strlen($words[0]) < 2) {
            return null;
        }

        // «nikita ogienko», «ИВАН ПЕТРОВ» → «Nikita Ogienko», «Иван Петров».
        $words = array_map(fn (string $w) => self::properCase($w), $words);

        // ФИО целиком («Ворона Николай Николаевич») → по имени-отчеству.
        if (count($words) === 3 && preg_match(self::PATRONYMIC, mb_strtolower($words[2]))) {
            return $words[1].' '.$words[2];
        }

        return implode(' ', $words);
    }

    /** «Здравствуйте, Иван!» или «Здравствуйте!». */
    public static function greeting(?string $raw): string
    {
        $name = self::personName($raw);

        return $name !== null ? "Здравствуйте, {$name}!" : 'Здравствуйте!';
    }

    /** «1 день», «3 дня», «7 дней». */
    public static function days(int $n): string
    {
        return $n.' '.self::plural($n, 'день', 'дня', 'дней');
    }

    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return $many;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $few;
        }

        return $n1 === 1 ? $one : $many;
    }

    /** 12.50 → «12,5%», 10.0 → «10%». */
    public static function percent(float $value): string
    {
        $s = rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');

        return $s.'%';
    }

    /**
     * Пункт Markdown-списка. Символы разметки в тексте (звёздочки, подчёркивания,
     * скобки) экранируются — название позиции не должно стать курсивом или ссылкой.
     */
    public static function listItem(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return '- '.addcslashes($text, '\\*_[]`#<>');
    }

    /**
     * В отрендеренном тексте шаблона: «7 дн.» → «7 дней». Шаблоны правят в
     * админке, поэтому чиним результат, а не только наши дефолты.
     */
    public static function expandDays(string $text): string
    {
        // Точка после «дн.» — и сокращение, и, бывает, конец предложения: если
        // дальше начинается новое предложение (абзац или заглавная), её оставляем.
        // В конце строки (тема письма) — убираем.
        return preg_replace_callback(
            '/(\d+)\s*дн\.(?=([\s)\],;:!?]|$))(?=(\s*\n|\s+\p{Lu})?)/u',
            fn ($m) => self::days((int) $m[1]).(($m[3] ?? '') !== '' ? '.' : ''),
            $text,
        ) ?? $text;
    }

    private static function properCase(string $word): string
    {
        $lower = mb_strtolower($word);
        $upper = mb_strtoupper($word);
        if ($word !== $lower && $word !== $upper) {
            return $word; // уже смешанный регистр — как написал человек (McDonald, Анна-Мария)
        }

        return mb_convert_case($lower, MB_CASE_TITLE);
    }
}
