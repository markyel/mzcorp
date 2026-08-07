<?php

namespace App\Livewire\Concerns;

use App\Models\EmailMessage;

/**
 * Рендер тела письма для CRM-треда: cid: → inline-роут вложений + сворачивание
 * цитат в `<details>` (по умолчанию закрыт). Общий код переписки заявки и
 * переписки с поставщиком — рендерится в sandbox-iframe (srcdoc).
 *
 * ВНИМАНИЕ: копия логики из App\Livewire\Requests\Detail (исторически там).
 * При правке синхронизировать. TODO: Detail тоже перевести на этот трейт.
 */
trait RendersEmailBody
{
    /**
     * Заменить cid:NNN в src/href HTML body на inline-роут вложений + свернуть
     * цитаты в `<details>`.
     */
    public function bodyHtmlFor(EmailMessage $email): ?string
    {
        if (! $email->body_html) {
            return null;
        }

        $messageId = $email->id;

        $html = preg_replace_callback(
            '/(src|href)\s*=\s*(["\'])cid:([^"\']+)\2/i',
            function ($m) use ($messageId) {
                $url = route('attachments.inline', [
                    'emailMessage' => $messageId,
                    'contentId' => rawurlencode($m[3]),
                ]);

                return $m[1].'='.$m[2].$url.$m[2];
            },
            $email->body_html
        ) ?? $email->body_html;

        return $this->collapseQuotedBlocks($html);
    }

    private function collapseQuotedBlocks(string $html): string
    {
        if (stripos($html, '<blockquote') === false
            && stripos($html, 'gmail_quote') === false
            && stripos($html, 'yahoo_quoted') === false
            && preg_match('/(From|От|Von)\s*:/iu', $html) !== 1
            && stripos($html, 'Original Message') === false
            && stripos($html, 'Исходное сообщение') === false) {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"?><div id="mylift-thread-root">'.$html.'</div>';
        $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return $html;
        }

        $xpath = new \DOMXPath($doc);

        $changed = $this->collapseOutlookStyleQuote($doc, $xpath);
        $changed = $this->collapseBlockquoteNodes($doc, $xpath) || $changed;

        if (! $changed) {
            return $html;
        }

        $root = $doc->getElementById('mylift-thread-root');
        if (! $root) {
            return $html;
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private function collapseOutlookStyleQuote(\DOMDocument $doc, \DOMXPath $xpath): bool
    {
        $candidates = $xpath->query(
            '//p[not(ancestor::blockquote) and not(ancestor::details)]'
            .' | //div[not(ancestor::blockquote) and not(ancestor::details)]'
        );
        if ($candidates === false || $candidates->length === 0) {
            return false;
        }

        $header = null;
        foreach ($candidates as $el) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $el->textContent ?? ''));
            if ($text === '' || mb_strlen($text) > 600) {
                continue;
            }
            if ($this->looksLikeReplyHeader($text)) {
                $header = $el;
                break;
            }
        }
        if ($header === null) {
            return false;
        }

        $node = $header;
        while ($node->parentNode instanceof \DOMElement
            && $node->parentNode->getAttribute('id') !== 'mylift-thread-root'
            && ! $this->hasMeaningfulPrecedingSibling($node)) {
            $node = $node->parentNode;
        }
        if (! $this->hasMeaningfulPrecedingSibling($node)) {
            return false;
        }

        $nodes = [$node];
        for ($n = $node->nextSibling; $n !== null; $n = $n->nextSibling) {
            $nodes[] = $n;
        }
        $this->wrapInDetails($doc, $nodes);

        return true;
    }

    private function collapseBlockquoteNodes(\DOMDocument $doc, \DOMXPath $xpath): bool
    {
        $nodes = $xpath->query(
            '//blockquote[not(ancestor::blockquote) and not(ancestor::details)]'
            .' | //div[contains(@class, "gmail_quote") and not(ancestor::blockquote) and not(ancestor::details)]'
            .' | //div[contains(@class, "yahoo_quoted") and not(ancestor::blockquote) and not(ancestor::details)]'
        );
        if ($nodes === false || $nodes->length === 0) {
            return false;
        }

        foreach ($nodes as $bq) {
            $attributionNodes = [];
            $prev = $bq->previousSibling;
            while ($prev !== null) {
                if ($this->looksLikeQuoteAttribution($prev)) {
                    $attributionNodes[] = $prev;
                    $prev = $prev->previousSibling;

                    continue;
                }
                if (($prev->nodeType === XML_TEXT_NODE && trim($prev->textContent) === '')
                    || ($prev->nodeType === XML_ELEMENT_NODE && strtolower($prev->nodeName) === 'br')
                ) {
                    $attributionNodes[] = $prev;
                    $prev = $prev->previousSibling;

                    continue;
                }
                break;
            }

            $this->wrapInDetails($doc, array_merge(array_reverse($attributionNodes), [$bq]));
        }

        return true;
    }

    /** @param array<int, \DOMNode> $nodes */
    private function wrapInDetails(\DOMDocument $doc, array $nodes): void
    {
        $first = $nodes[0] ?? null;
        if ($first === null || $first->parentNode === null) {
            return;
        }

        $details = $doc->createElement('details');
        $details->setAttribute('style', 'margin-top:6px;');

        $summary = $doc->createElement('summary', '· · · показать цитату');
        $summary->setAttribute(
            'style',
            'cursor:pointer;list-style:none;font-size:12px;color:#7280a0;'
            .'user-select:none;padding:2px 0;outline:none;'
        );
        $details->appendChild($summary);

        $first->parentNode->replaceChild($details, $first);
        $details->appendChild($first);
        foreach (array_slice($nodes, 1) as $node) {
            $details->appendChild($node);
        }
    }

    private function looksLikeReplyHeader(string $text): bool
    {
        if (preg_match('/^-{2,}\s*(Original Message|Исходное сообщение|Forwarded message|Пересылаемое сообщение|Перенаправленное сообщение)/iu', $text)) {
            return true;
        }
        if (preg_match('/(^|\s)(From|От)\s*:/iu', $text) !== 1) {
            return false;
        }
        $fields = 0;
        foreach (['/(Sent|Отправлено)\s*:/iu', '/(To|Кому)\s*:/iu', '/(Subject|Тема)\s*:/iu', '/(Date|Дата)\s*:/iu'] as $p) {
            if (preg_match($p, $text) === 1) {
                $fields++;
            }
        }

        return $fields >= 2;
    }

    private function hasMeaningfulPrecedingSibling(\DOMNode $node): bool
    {
        for ($p = $node->previousSibling; $p !== null; $p = $p->previousSibling) {
            if ($p->nodeType === XML_TEXT_NODE && trim($p->textContent) !== '') {
                return true;
            }
            if ($p->nodeType === XML_ELEMENT_NODE) {
                if (in_array(strtolower($p->nodeName), ['br', 'hr'], true)) {
                    continue;
                }
                if (trim($p->textContent) !== '') {
                    return true;
                }
                if ($p instanceof \DOMElement && $p->getElementsByTagName('img')->length > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function looksLikeQuoteAttribution(\DOMNode $node): bool
    {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return false;
        }
        $tag = strtolower($node->nodeName);
        if (! in_array($tag, ['div', 'p', 'span', 'blockquote'], true)) {
            return false;
        }
        $text = trim($node->textContent);
        if ($text === '' || mb_strlen($text) > 600) {
            return false;
        }
        $patterns = [
            '/^\s*Кому\s*:/iu', '/^\s*Тема\s*:/iu', '/^\s*От\s*:/iu', '/^\s*Дата\s*:/iu',
            '/^\s*To\s*:/i', '/^\s*From\s*:/i', '/^\s*Subject\s*:/i', '/^\s*Date\s*:/i',
            '/-{3,}\s*(Original message|Перенаправленное сообщение|Forwarded message|Пересылаемое сообщение)/iu',
            '/\d{1,2}[\.\/]\d{1,2}[\.\/]\d{2,4}.*(написал|wrote)/iu',
            '/\d{1,2}\s+\p{L}+\s+\d{4}.*(написал|wrote)/iu',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $text)) {
                return true;
            }
        }

        return false;
    }
}
