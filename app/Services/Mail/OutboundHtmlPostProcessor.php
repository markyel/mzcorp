<?php

namespace App\Services\Mail;

/**
 * Пост-обработка HTML исходящего письма перед сборкой MIME.
 *
 * 1. cidify(): картинки, вставленные в тело из редактора, лежат в HTML как
 *    ссылки на наш inline-роут (`/attachments/cid/{message}/{cid}`) — так их
 *    видно в превью и в треде CRM. В самом письме они должны быть `cid:`-
 *    ссылками на inline-части MIME (Gmail режет data:, внешние картинки
 *    прячет до «показать изображения»). Возвращает HTML и список cid, которые
 *    реально используются — по нему OutgoingMailMimeBuilder встраивает файлы,
 *    а неиспользуемые inline-вложения черновика не отправляются.
 *
 * 2. emailSafe(): редактор отдаёт «голую» семантическую разметку (таблица
 *    без рамок, картинка без ограничения ширины). Почтовые клиенты внешних
 *    стилей не имеют, поэтому добавляем inline-CSS там, где его нет.
 *
 * Чистые функции, без зависимостей — покрыты unit-тестами.
 */
class OutboundHtmlPostProcessor
{
    private const TABLE_STYLE = 'border-collapse:collapse;';

    private const CELL_STYLE = 'border:1px solid #d0d5dd;padding:4px 8px;vertical-align:top;';

    private const HEADER_STYLE = 'border:1px solid #d0d5dd;padding:4px 8px;vertical-align:top;background:#f1f5f9;font-weight:600;text-align:left;';

    private const IMG_STYLE = 'max-width:100%;height:auto;';

    private const BLOCKQUOTE_STYLE = 'margin:6px 0;padding-left:12px;border-left:2px solid #d0d5dd;color:#475569;';

    /**
     * Ссылки на inline-роут вложений письма → `cid:`.
     *
     * @return array{html: string, cids: list<string>}
     */
    public function cidify(string $html, int $messageId): array
    {
        if ($html === '' || $messageId <= 0) {
            return ['html' => $html, 'cids' => []];
        }

        $cids = [];
        $pattern = '#(src|href)\s*=\s*(["\'])[^"\']*?/attachments/cid/' . $messageId . '/([^"\'?]+)(?:\?[^"\']*)?\2#i';
        $out = preg_replace_callback($pattern, function (array $m) use (&$cids): string {
            $cid = trim(rawurldecode($m[3]), "<> \t");
            if ($cid === '') {
                return $m[0];
            }
            $cids[] = $cid;

            return $m[1] . '=' . $m[2] . 'cid:' . $cid . $m[2];
        }, $html);

        return ['html' => $out ?? $html, 'cids' => array_values(array_unique($cids))];
    }

    /**
     * cid, на которые ссылается HTML (для чистки неиспользуемых inline-картинок
     * черновика): и `cid:`-ссылки, и ссылки на inline-роут этого письма.
     *
     * @return list<string>
     */
    public function referencedCids(string $html, int $messageId): array
    {
        $cids = $this->cidify($html, $messageId)['cids'];
        if (preg_match_all('#(?:src|href)\s*=\s*["\']cid:([^"\']+)["\']#i', $html, $m)) {
            foreach ($m[1] as $cid) {
                $cids[] = trim(rawurldecode($cid), "<> \t");
            }
        }

        return array_values(array_unique(array_filter($cids)));
    }

    /** Inline-стили для таблиц, ячеек, картинок и цитат, у которых стиля нет. */
    public function emailSafe(string $html): string
    {
        if ($html === '' || ! preg_match('/<(table|td|th|img|blockquote)\b/i', $html)) {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"?><div id="mylift-email-safe-root">' . $html . '</div>';
        $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return $html;
        }
        $root = $doc->getElementById('mylift-email-safe-root');
        if (! $root) {
            return $html;
        }

        $map = [
            'table' => self::TABLE_STYLE,
            'td' => self::CELL_STYLE,
            'th' => self::HEADER_STYLE,
            'img' => self::IMG_STYLE,
            'blockquote' => self::BLOCKQUOTE_STYLE,
        ];
        foreach ($map as $tag => $style) {
            foreach ($root->getElementsByTagName($tag) as $el) {
                /** @var \DOMElement $el */
                $own = trim((string) $el->getAttribute('style'));
                $el->setAttribute('style', $own === '' ? $style : rtrim($own, '; ') . ';' . $style);
            }
        }
        // Пустые ячейки схлопываются в Outlook — держим высоту неразрывным пробелом.
        foreach (['td', 'th'] as $tag) {
            foreach ($root->getElementsByTagName($tag) as $el) {
                if (trim($el->textContent) === '' && $el->getElementsByTagName('img')->length === 0) {
                    $el->appendChild($doc->createEntityReference('nbsp'));
                }
            }
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }
}
