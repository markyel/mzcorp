<?php

namespace App\Services\Mail;

/**
 * Allowlist-санитайзер HTML тела письма из богатого редактора почтового
 * клиента. Без внешних зависимостей (DOMDocument). Оставляет только безопасные
 * теги форматирования; вырезает script/style, inline-обработчики; из
 * атрибутов — a[href] (http/https/mailto), img[src] (только `cid:` и наш
 * inline-роут вложений), размеры, colspan/rowspan и `style` с белым списком
 * CSS-свойств (цвет, выравнивание, рамки, отступы, размеры).
 *
 * Цель — не «очистить чужой HTML от XSS вообще», а нормализовать вывод
 * редактора до предсказуемого набора тегов перед сохранением/отправкой.
 */
class HtmlSanitizer
{
    /** Разрешённые теги. */
    private const ALLOWED = [
        'p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'mark',
        'a', 'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'hr', 'pre', 'code', 'sub', 'sup', 'img',
        // Таблицы — чтобы структура пересланных/вставленных писем не рассыпалась.
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
    ];

    /** Атрибуты, допустимые на конкретных тегах (кроме a[href] / img[src] / style). */
    private const ATTR_ALLOW = [
        'td' => ['colspan', 'rowspan', 'width'],
        'th' => ['colspan', 'rowspan', 'width'],
        'col' => ['span', 'width'],
        'colgroup' => ['span'],
        'table' => ['width', 'cellpadding', 'cellspacing', 'border'],
        'img' => ['alt', 'width', 'height', 'title'],
    ];

    /** CSS-свойства, которые оставляем в style="…" (значения проверяются отдельно). */
    private const STYLE_PROPS = [
        'color', 'background-color', 'background', 'text-align', 'vertical-align',
        'font-weight', 'font-style', 'font-size', 'font-family', 'text-decoration', 'line-height',
        'width', 'height', 'max-width', 'min-width',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-collapse', 'border-color', 'border-radius',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'white-space', 'display',
    ];

    /** Теги, вырезаемые вместе с содержимым. */
    private const DROP = ['script', 'style', 'head', 'meta', 'link', 'iframe', 'object', 'embed'];

    public function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"?><div id="mylift-sanitize-root">'.$html.'</div>';
        $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            // Не смогли распарсить — возвращаем экранированный plain.
            return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8'));
        }

        $root = $doc->getElementById('mylift-sanitize-root');
        if (! $root) {
            return '';
        }

        $this->clean($root, $doc);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /** Рекурсивно чистит детей узла (на месте). */
    private function clean(\DOMNode $node, \DOMDocument $doc): void
    {
        // Копия списка — модифицируем по ходу.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                // комментарии / CDATA — удаляем
                $node->removeChild($child);

                continue;
            }

            /** @var \DOMElement $child */
            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);

                continue;
            }

            // Сначала вычистить потомков.
            $this->clean($child, $doc);

            if (! in_array($tag, self::ALLOWED, true)) {
                // Неразрешённый тег — «разворачиваем» (оставляем детей).
                $this->unwrap($child);

                continue;
            }

            $this->stripAttributes($child, $tag);
        }
    }

    private function stripAttributes(\DOMElement $el, string $tag): void
    {
        $extra = self::ATTR_ALLOW[$tag] ?? [];
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $name = strtolower($attr->name);
            if ($tag === 'a' && $name === 'href' && $this->safeHref($attr->value)) {
                continue;
            }
            if ($tag === 'img' && $name === 'src' && $this->safeImageSrc($attr->value)) {
                continue;
            }
            if ($name === 'style') {
                $clean = $this->cleanStyle($attr->value);
                if ($clean === '') {
                    $el->removeAttribute($attr->name);
                } else {
                    $el->setAttribute('style', $clean);
                }

                continue;
            }
            if (in_array($name, $extra, true) && $this->safeAttrValue($attr->value)) {
                continue;
            }
            $el->removeAttribute($attr->name);
        }
        // Картинка без допустимого src — не нужна вовсе.
        if ($tag === 'img' && ! $el->hasAttribute('src')) {
            $el->parentNode?->removeChild($el);

            return;
        }
        // Внешние ссылки — открывать в новой вкладке безопасно.
        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function safeHref(string $href): bool
    {
        $href = trim($href);
        if ($href === '') {
            return false;
        }

        return (bool) preg_match('#^(https?:|mailto:)#i', $href);
    }

    /**
     * Картинки в теле: только `cid:` (пересылка / уже отправленное) и наш
     * inline-роут вложений (картинки, загруженные из редактора). Внешние
     * http-картинки и data: не пропускаем — трекинг-пиксели и мегабайты base64.
     */
    private function safeImageSrc(string $src): bool
    {
        $src = trim($src);
        if ($src === '') {
            return false;
        }
        if (preg_match('#^cid:[^\s"\']+$#i', $src)) {
            return true;
        }
        if (! preg_match('#^(?:https?://[^/\s]+)?/attachments/cid/\d+/[^\s"\']+$#i', $src)) {
            return false;
        }
        $host = parse_url($src, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return true; // относительный путь
        }
        $own = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $own !== null && $own !== false && strcasecmp((string) $host, (string) $own) === 0;
    }

    /** Простые значения атрибутов (числа, проценты, короткие слова). */
    private function safeAttrValue(string $value): bool
    {
        return (bool) preg_match('/^[\w.%\- ]{0,64}$/u', trim($value));
    }

    /** style="…" → только белый список свойств с безопасными значениями. */
    private function cleanStyle(string $style): string
    {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            if (! str_contains($decl, ':')) {
                continue;
            }
            [$prop, $value] = explode(':', $decl, 2);
            $prop = strtolower(trim($prop));
            $value = trim($value);
            if (! in_array($prop, self::STYLE_PROPS, true) || $value === '') {
                continue;
            }
            // Без url(), expression(), javascript:, комментариев и экранирований.
            if (preg_match('#url\s*\(|expression|javascript:|/\*|\\\\|@import|behavior#i', $value)) {
                continue;
            }
            if (! preg_match('#^[\w\s.,%()\#\-\'"/]{1,120}$#u', $value)) {
                continue;
            }
            $out[] = $prop . ':' . $value;
        }

        return implode(';', $out);
    }

    /** Заменить элемент его детьми (сохранив содержимое). */
    private function unwrap(\DOMElement $el): void
    {
        $parent = $el->parentNode;
        if (! $parent) {
            return;
        }
        while ($el->firstChild) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }
}
