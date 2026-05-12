<?php

namespace App\Services\Web;

/**
 * Извлечение http(s)-ссылок из тела входящего письма.
 *
 * Источники:
 *  - cleaned plain-text (после `EmailTextCleanerService::cleanInboundReferenceText`)
 *  - сырой body_html (чтобы выловить ссылки внутри <a href="">, которые
 *    могли отображаться как «нажмите здесь» — в plain их не видно)
 *
 * Результат — список уникальных, нормализованных URL'ов, отсортированных
 * по порядку появления в тексте (важно для cap по N — первые попавшиеся
 * выигрывают).
 */
class UrlExtractor
{
    public function __construct(private readonly WebSecurity $sec)
    {
    }

    /**
     * @return list<string>  Нормализованные URL'ы (см. WebSecurity::normalizeUrl).
     */
    public function extract(?string $plain, ?string $html = null, int $max = 10): array
    {
        $candidates = [];

        if ($plain !== null && $plain !== '') {
            foreach ($this->extractFromText($plain) as $u) {
                $candidates[] = $u;
            }
        }

        if ($html !== null && $html !== '') {
            foreach ($this->extractFromHtml($html) as $u) {
                $candidates[] = $u;
            }
        }

        $seen = [];
        $result = [];
        foreach ($candidates as $url) {
            $norm = $this->sec->normalizeUrl($url);
            if ($norm === null) {
                continue;
            }
            if (isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $result[] = $norm;
            if (count($result) >= $max) {
                break;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function extractFromText(string $text): array
    {
        // Базовый pattern: http/https, без пробелов и угловых скобок;
        // отрезаем хвостовую пунктуацию (.,;:!?)»]'"<>») которая часто
        // прилипает в письмах при автоматическом разворачивании URL.
        $pattern = '~\bhttps?://[^\s<>"\']+~iu';
        if (! preg_match_all($pattern, $text, $m)) {
            return [];
        }
        $out = [];
        foreach ($m[0] as $url) {
            $url = rtrim($url, ".,;:!?»)\]'\"<>");
            if ($url !== '') {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * Берём только href= из <a>, чтобы не подцеплять img src/script src/
     * tracker-пиксели.
     *
     * @return list<string>
     */
    private function extractFromHtml(string $html): array
    {
        $out = [];
        // <a ... href="..." ...> — собираем DOMDocument чтобы не ловить
        // ложные href в комментариях и атрибутах с экранированием.
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            // suppress html5/encoding warnings — нам нужно best-effort
            $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
            $anchors = $doc->getElementsByTagName('a');
            foreach ($anchors as $a) {
                /** @var \DOMElement $a */
                $href = trim($a->getAttribute('href'));
                if ($href === '') {
                    continue;
                }
                if (! preg_match('~^https?://~i', $href)) {
                    continue;
                }
                $out[] = $href;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        return $out;
    }
}
