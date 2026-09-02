<?php

namespace App\Services\Mail;

/**
 * Allowlist-санитайзер HTML тела письма из богатого редактора почтового
 * клиента. Без внешних зависимостей (DOMDocument). Оставляет только безопасные
 * теги форматирования; вырезает script/style, все атрибуты кроме a[href]
 * (только http/https/mailto), inline-обработчики и стили.
 *
 * Цель — не «очистить чужой HTML от XSS вообще», а нормализовать вывод
 * contenteditable до предсказуемого набора тегов перед сохранением/отправкой.
 */
class HtmlSanitizer
{
    /** Разрешённые теги. */
    private const ALLOWED = [
        'p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u',
        'a', 'ul', 'ol', 'li', 'blockquote', 'h3', 'h4',
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
        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $name = strtolower($attr->name);
            if ($tag === 'a' && $name === 'href' && $this->safeHref($attr->value)) {
                continue;
            }
            $el->removeAttribute($attr->name);
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
