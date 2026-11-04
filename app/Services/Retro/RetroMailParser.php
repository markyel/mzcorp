<?php

namespace App\Services\Retro;

/**
 * Разбор письма для ретрозагрузки: заголовки, номера заявок, текст и имена
 * вложений из сырого письма. Без обращений к базе и почте.
 */
class RetroMailParser
{
    /** Значение заголовка из сырого блока заголовков. */
    public static function headerValue(string $raw, string $name): string
    {
        $raw = (string) preg_replace("/\r?\n[ \t]+/", ' ', $raw);

        return preg_match('/^'.preg_quote($name, '/').':\s*(.*)$/mi', $raw, $m) ? trim($m[1]) : '';
    }

    public static function decoded(string $value): string
    {
        // iconv портит уже раскодированную кириллицу, поэтому только там, где есть MIME-слова.
        if (! str_contains($value, '=?')) {
            return $value;
        }
        $d = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $d === false ? $value : $d;
    }

    /** Номера заявок вида M-2026-1234 (буква может быть кириллической), без ведущих нулей. */
    public static function codes(string $text): array
    {
        preg_match_all('/(?<![\p{L}\d])[MМ]-(20\d{2})-(\d{3,6})(?!\d)/u', $text, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) {
            $out['M-'.$x[1].'-'.(ltrim($x[2], '0') ?: '0')] = true;
        }

        return array_keys($out);
    }

    /** Номера из подвала «№ заявки: M-2026-1234» (см. OutgoingMailMimeBuilder). */
    public static function footerCodes(string $text): array
    {
        preg_match_all('/№\s*заявки\s*:?\s*([MМ]-20\d{2}-\d{3,6})(?!\d)/u', $text, $m);

        return self::codes(implode(' ', $m[1]));
    }

    /**
     * Текстовые части письма. Письмо может быть обрезано по длине: разбираем,
     * что успело прийти, вложения пропускаем.
     *
     * @return array{plain:string, html:string}
     */
    public static function bodyParts(string $raw): array
    {
        $out = ['plain' => '', 'html' => ''];
        self::walk($raw, 0, $out);

        return $out;
    }

    /**
     * Вложения целого письма, с содержимым.
     *
     * @return list<array{filename:string, mime:string, content_id:?string, inline:bool, content:string}>
     */
    public static function attachments(string $raw): array
    {
        $out = [];
        self::walkAttachments($raw, 0, $out);

        return $out;
    }

    /** Значение параметра заголовка: обычное, MIME-слова и формы RFC 2231, в том числе разбитые на части. */
    public static function headerParam(string $header, string $name): string
    {
        $quoted = preg_quote($name, '/');
        if (preg_match_all('/(?:^|;)\s*'.$quoted.'\*(\d+)(\*?)=("[^"]*"|[^;]*)/i', $header, $m, PREG_SET_ORDER)) {
            usort($m, fn ($a, $b) => (int) $a[1] <=> (int) $b[1]);
            $value = implode('', array_map(fn ($x) => trim($x[3], " \t\""), $m));

            return $m[0][2] === '*' ? self::attachmentName($value) : self::attachmentName($value);
        }
        if (preg_match('/(?:^|;)\s*'.$quoted.'\*=("[^"]*"|[^;]*)/i', $header, $x)) {
            return self::attachmentName(trim($x[1], " \t\""));
        }
        if (preg_match('/(?:^|;)\s*'.$quoted.'=("[^"]*"|[^;]*)/i', $header, $x)) {
            return self::attachmentName(trim($x[1], " \t\""));
        }

        return '';
    }

    private static function walkAttachments(string $raw, int $depth, array &$out): void
    {
        if ($depth > 8) {
            return;
        }
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
        $head = $parts[0] ?? '';
        $body = $parts[1] ?? '';
        $type = self::headerValue($head, 'Content-Type') ?: 'text/plain';
        if (preg_match('/^multipart\//i', $type) && preg_match('/boundary="?([^";\r\n]+)"?/i', $type, $b)) {
            foreach (explode('--'.trim($b[1]), $body) as $part) {
                $part = ltrim($part, "\r\n");
                if ($part !== '' && ! str_starts_with($part, '--')) {
                    self::walkAttachments($part, $depth + 1, $out);
                }
            }

            return;
        }
        $disposition = self::headerValue($head, 'Content-Disposition');
        $filename = self::headerParam($disposition, 'filename') ?: self::headerParam($type, 'name');
        $isText = (bool) preg_match('/^text\/(plain|html)/i', $type);
        if ($filename === '' && ($isText || ! preg_match('/^attachment/i', $disposition) && ! preg_match('/^(image|application|audio|video)\//i', $type))) {
            return;
        }
        $encoding = strtolower(self::headerValue($head, 'Content-Transfer-Encoding'));
        if ($encoding === 'base64') {
            $content = (string) base64_decode((string) preg_replace('/\s+/', '', $body));
        } elseif ($encoding === 'quoted-printable') {
            $content = quoted_printable_decode($body);
        } else {
            $content = (string) preg_replace("/\r?\n$/", '', $body);
        }
        $mime = strtolower(trim(explode(';', $type)[0]));
        if ($filename === '') {
            $filename = 'attachment-'.substr(md5($content), 0, 8).'.'.(explode('/', $mime)[1] ?? 'bin');
        }
        $cid = trim(self::headerValue($head, 'Content-ID'), " <>\t");
        $out[] = [
            'filename' => $filename,
            'mime' => mb_substr($mime, 0, 128),
            'content_id' => $cid !== '' ? $cid : null,
            'inline' => (bool) preg_match('/^inline/i', $disposition),
            'content' => $content,
        ];
    }

    /** Текст письма для поиска номеров: теги заменены пробелами, иначе соседние числа склеиваются. */
    public static function searchableText(array $parts): string
    {
        return $parts['plain'].' '.self::htmlToText($parts['html']);
    }

    public static function htmlToText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = (string) preg_replace('/<(style|script)\b.*?<\/\1>/is', ' ', $html);

        return html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Имена вложений из ответа FETCH с BODYSTRUCTURE. */
    public static function attachmentNames(string $fetchLine): array
    {
        if (! str_contains($fetchLine, 'BODYSTRUCTURE')) {
            return [];
        }
        preg_match_all('/"(?:FILENAME|NAME)\*?"\s+"([^"]*)"/i', $fetchLine, $m);
        $out = [];
        foreach ($m[1] as $name) {
            $name = self::attachmentName($name);
            if ($name !== '' && ! self::isOwnInlineAsset($name)) {
                $out[$name] = true;
            }
        }

        return array_map('strval', array_keys($out));
    }

    /** Логотип и баннер, которые CRM вставляет в каждое письмо: это не вложения. */
    public static function isOwnInlineAsset(string $name): bool
    {
        return (bool) preg_match('/^mylift-(logo|promo)\b/i', $name);
    }

    /** Текст письма для хранения: у писем только с HTML текст берётся из него, как в MessagePersister. */
    public static function plainBody(array $parts): string
    {
        return trim($parts['plain']) !== '' ? $parts['plain'] : trim(self::htmlToText($parts['html']));
    }

    /** Имя вложения: MIME-кодировка или форма RFC 2231 (utf-8''%D0%A1...). */
    public static function attachmentName(string $name): string
    {
        if (preg_match("/^([\w-]+)'[^']*'(.*)$/", $name, $m)) {
            $decoded = rawurldecode($m[2]);
            $name = strcasecmp($m[1], 'utf-8') === 0 ? $decoded : self::toUtf8($decoded, $m[1]);
        }

        return trim(self::decoded($name));
    }

    /**
     * Документ по имени файла: наши шаблоны из 1С «Предложение МЗ-360610 от
     * 2026-06-01_12-00-00.pdf» и «Счет МЗ-7143 от 2026-06-01_12-00-00.pdf».
     *
     * @return array{type:string, number:string, date:?string}|null type: quote | invoice
     */
    public static function documentFromFilename(string $name): ?array
    {
        $name = trim(str_replace('_', ' ', $name));
        $type = null;
        $number = null;
        if (preg_match('/^(?:\d+[.\s]+)?предложени[ея]\s*(?:№\s*)?[МM][З3]-?\s*(\d+)/iu', $name, $m)) {
            [$type, $number] = ['quote', $m[1]];
        } elseif (preg_match('/^(?:\d+[.\s]+)?сч[её]т(?:-договор)?(?:\s+на\s+оплату)?\s*(?:№\s*)?(?:[МM][З3]-?\s*)?(\d{3,})/iu', $name, $m)) {
            [$type, $number] = ['invoice', $m[1]];
        }
        if ($type === null) {
            return null;
        }
        $date = null;
        if (preg_match('/от\s+(\d{4})-(\d{2})-(\d{2})/u', $name, $d)) {
            $date = checkdate((int) $d[2], (int) $d[3], (int) $d[1]) ? "$d[1]-$d[2]-$d[3]" : null;
        } elseif (preg_match('/от\s+(\d{2})\.(\d{2})\.(\d{4})/u', $name, $d)) {
            $date = checkdate((int) $d[2], (int) $d[1], (int) $d[3]) ? "$d[3]-$d[2]-$d[1]" : null;
        }

        return ['type' => $type, 'number' => $number, 'date' => $date];
    }

    /**
     * Суммы из текста PDF наших КП и счетов (шаблон 1С): «Итого: 1 234,50», «Итого со
     * скидкой: …», «В том числе НДС: …», «Всего наименований 3, на сумму 1 234,50 RUB».
     *
     * @return array{subtotal:?float, discounted:?float, vat:?float, sum:?float, lines:?int, currency:?string}
     */
    public static function documentTotals(string $text): array
    {
        $number = '(\d[\d\s\x{00A0}]*(?:[.,]\d{1,2})?)';
        $amount = fn (string $pattern) => preg_match($pattern, $text, $m)
            ? (float) str_replace([' ', "\u{00A0}", ','], ['', '', '.'], preg_replace('/\s+/u', ' ', trim($m[1])))
            : null;

        $out = [
            'subtotal' => $amount('/Итого\s*:\s*'.$number.'/u'),
            'discounted' => $amount('/Итого\s+со\s+скидкой\s*:\s*'.$number.'/u'),
            'vat' => $amount('/В\s+том\s+числе\s+НДС\s*:\s*'.$number.'/u'),
            'sum' => null,
            'lines' => null,
            'currency' => null,
        ];
        if (preg_match('/Всего\s+наименований\s+(\d+)\s*,\s*на\s+сумму\s+'.$number.'\s*([A-Z]{3})?/u', $text, $m)) {
            $out['lines'] = (int) $m[1];
            $out['sum'] = (float) str_replace([' ', "\u{00A0}", ','], ['', '', '.'], preg_replace('/\s+/u', ' ', trim($m[2])));
            $out['currency'] = $m[3] ?? null;
        }

        return $out;
    }

    /** Адреса заголовка: [{email, name}], в нижнем регистре, без пустых. */
    public static function addresses(string $header): array
    {
        $out = [];
        preg_match_all('/(?:"([^"]*)"|([^",<>]*))\s*<\s*([^<>\s]+@[^<>\s]+)\s*>|([\w.+\'-]+@[\w.-]+\.\w+)/u', $header, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $email = mb_strtolower(trim($x[3] ?? '') ?: trim($x[4] ?? ''));
            if ($email === '' || isset($out[$email])) {
                continue;
            }
            $name = trim(($x[1] ?? '') !== '' ? $x[1] : ($x[2] ?? ''));
            $out[$email] = ['email' => $email, 'name' => $name !== '' ? $name : null];
        }

        return array_values($out);
    }

    /** Message-ID из заголовка: без угловых скобок, как хранит MessagePersister. */
    public static function ids(string $header): array
    {
        preg_match_all('/<([^<>\s]+)>/', $header, $m);
        $out = $m[1];
        if ($out === []) {
            foreach (preg_split('/[\s,;]+/', $header) ?: [] as $id) {
                $id = trim($id, "<>, \t\r\n;");
                if ($id !== '' && str_contains($id, '@')) {
                    $out[] = $id;
                }
            }
        }

        return array_values(array_unique($out));
    }

    public static function timestamp(?string $date, ?string $fallback = null): ?int
    {
        foreach ([$date, $fallback] as $value) {
            $value = trim((string) preg_replace('/\([^)]*\)/', '', (string) $value));
            if ($value !== '' && ($ts = strtotime($value)) !== false) {
                return $ts;
            }
        }

        return null;
    }

    private static function walk(string $raw, int $depth, array &$out): void
    {
        if ($depth > 6) {
            return;
        }
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
        $head = $parts[0] ?? '';
        $body = $parts[1] ?? '';
        $type = self::headerValue($head, 'Content-Type') ?: 'text/plain';

        if (preg_match('/^multipart\//i', $type) && preg_match('/boundary="?([^";\r\n]+)"?/i', $type, $b)) {
            foreach (explode('--'.trim($b[1]), $body) as $part) {
                $part = ltrim($part, "\r\n");
                if ($part !== '' && ! str_starts_with($part, '--')) {
                    self::walk($part, $depth + 1, $out);
                }
            }

            return;
        }
        if (! preg_match('/^text\/(plain|html)/i', $type, $t)
            || preg_match('/^attachment/i', self::headerValue($head, 'Content-Disposition'))) {
            return;
        }
        $encoding = strtolower(self::headerValue($head, 'Content-Transfer-Encoding'));
        if ($encoding === 'base64') {
            $clean = (string) preg_replace('/[^A-Za-z0-9+\/=]/', '', $body);
            $body = (string) base64_decode(substr($clean, 0, strlen($clean) - strlen($clean) % 4));
        } elseif ($encoding === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        }
        $charset = preg_match('/charset="?([^";\s]+)"?/i', $type, $c) ? $c[1] : 'utf-8';
        $key = strtolower($t[1]);
        if ($out[$key] === '') {
            $out[$key] = self::toUtf8($body, $charset);
        }
    }

    private static function toUtf8(string $text, string $charset): string
    {
        $charset = strtolower(trim($charset)) ?: 'utf-8';
        if ($charset === 'utf-8' || $charset === 'us-ascii') {
            return mb_scrub($text, 'UTF-8');
        }
        $converted = @iconv($charset, 'UTF-8//IGNORE', $text);

        return $converted === false ? mb_scrub($text, 'UTF-8') : $converted;
    }
}
