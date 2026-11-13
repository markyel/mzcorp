<?php

namespace App\Support;

/**
 * Тело письма, которое webklex отдал «вложением».
 *
 * webklex/php-imap 6.2 (Structure::parsePart) раскрывает вложенный multipart, только
 * если в нём больше одной части. Площадка ОТС (otc-prom.ru, otc-snab.ru) шлёт
 * multipart/mixed → multipart/alternative с одним text/plain: блок alternative
 * целиком становится вложением без имени («d5e59bf9», text/plain), а тело письма —
 * пустым. Заявку MZ2026-01489 (13.11.2026) классификатор счёл нерелевантной, а после
 * ручного создания позиции собрались из темы письма — из десяти реальных ни одной.
 *
 * Здесь такой блок разбирается заново: текстовые части без имени файла — тело письма.
 */
final class NestedMimePart
{
    /**
     * Текст и HTML из неразобранного MIME-блока; null — это не такой блок
     * или в нём есть настоящие вложения (тогда его не трогаем).
     *
     * @return array{plain: string, html: string}|null
     */
    public static function bodies(string $content): ?array
    {
        $raw = str_replace("\r\n", "\n", ltrim($content, "\r\n"));
        if (! preg_match('/^--(\S+)\n/', $raw, $m) || ! preg_match('/^content-type:/im', substr($raw, 0, 1000))) {
            return null;
        }

        $leaves = self::leaves($raw, $m[1]);
        $out = ['plain' => '', 'html' => ''];
        foreach ($leaves as $leaf) {
            $isText = in_array($leaf['type'], ['text/plain', 'text/html'], true);
            if (! $isText || $leaf['filename'] || $leaf['disposition'] === 'attachment') {
                return null;
            }
            $key = $leaf['type'] === 'text/html' ? 'html' : 'plain';
            if ($out[$key] === '') {
                $out[$key] = $leaf['body'];
            }
        }

        return trim($out['plain']) === '' && trim($out['html']) === '' ? null : $out;
    }

    /**
     * @return list<array{type: string, filename: bool, disposition: string, body: string}>
     */
    private static function leaves(string $raw, string $boundary, int $depth = 0): array
    {
        if ($depth > 5) {
            return [];
        }
        $leaves = [];
        $chunks = preg_split('/^--'.preg_quote($boundary, '/').'(?:--)?[ \t]*$/m', $raw) ?: [];
        // Первый кусок — преамбула до первой границы.
        foreach (array_slice($chunks, 1) as $chunk) {
            $chunk = ltrim($chunk, "\n");
            if (trim($chunk) === '') {
                continue;
            }
            [$head, $body] = array_pad(explode("\n\n", $chunk, 2), 2, '');
            $headers = self::headers($head);
            $ctype = $headers['content-type'] ?? 'text/plain';
            $type = strtolower(trim(explode(';', $ctype)[0]));

            if (str_starts_with($type, 'multipart/') && preg_match('/boundary\s*=\s*"?([^";\s]+)"?/i', $ctype, $b)) {
                array_push($leaves, ...self::leaves($body, $b[1], $depth + 1));

                continue;
            }

            $disposition = strtolower(trim(explode(';', $headers['content-disposition'] ?? '')[0]));
            $leaves[] = [
                'type' => $type,
                'filename' => (bool) preg_match('/(?:file)?name\*?\s*=/i', $ctype.';'.($headers['content-disposition'] ?? '')),
                'disposition' => $disposition,
                'body' => self::decode(rtrim($body, "\n"), $headers['content-transfer-encoding'] ?? '', $ctype),
            ];
        }

        return $leaves;
    }

    /** @return array<string, string> имя заголовка в нижнем регистре → значение (свёрнутые строки склеены) */
    private static function headers(string $head): array
    {
        $out = [];
        foreach (explode("\n", preg_replace('/\n[ \t]+/', ' ', $head) ?? $head) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $out[strtolower(trim($name))] = trim($value);
            }
        }

        return $out;
    }

    private static function decode(string $body, string $encoding, string $ctype): string
    {
        $bytes = match (strtolower(trim($encoding))) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
        $charset = preg_match('/charset\s*=\s*"?([^";\s]+)"?/i', $ctype, $c) ? strtolower($c[1]) : 'utf-8';
        if (! in_array($charset, ['utf-8', 'utf8', 'us-ascii'], true)) {
            try {
                $bytes = mb_convert_encoding($bytes, 'UTF-8', $charset);
            } catch (\ValueError) {
                // неизвестная кодировка — оставляем как есть
            }
        }

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
    }
}
