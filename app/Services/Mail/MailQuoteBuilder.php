<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;

/**
 * Сборка quoted-блока оригинального письма для reply (Phase 1.9).
 *
 * Yandex-style HTML blockquote + Gmail-style plain `>`-prefix — совместимо
 * со всеми клиентами и нашим же iframe-рендерингом в треде Detail.
 */
class MailQuoteBuilder
{
    /**
     * @return array{html: string, plain: string}
     */
    public function build(EmailMessage $replyTo): array
    {
        $date = $replyTo->sent_at?->toDateTimeString() ?? '';
        $from = trim(($replyTo->from_name ? $replyTo->from_name . ' ' : '')
            . '<' . $replyTo->from_email . '>');

        $headerPlain = sprintf('On %s, %s wrote:', $date, $from);
        $headerHtml = htmlspecialchars($headerPlain, ENT_QUOTES, 'UTF-8');

        $originalHtml = (string) ($replyTo->body_html ?: nl2br(htmlspecialchars(
            (string) $replyTo->body_plain, ENT_QUOTES, 'UTF-8'
        )));

        $html = '<blockquote type="cite" '
            . 'style="margin:0 0 0 0.8ex;border-left:2px solid #ccc;padding-left:10px;color:#555;">'
            . '<p style="margin:0 0 8px;font-size:12px;color:#888;">' . $headerHtml . '</p>'
            . $originalHtml
            . '</blockquote>';

        $originalPlain = (string) ($replyTo->body_plain ?: strip_tags((string) $replyTo->body_html));
        $plainQuoted = preg_replace('/^/m', '> ', $originalPlain);
        $plain = $headerPlain . "\n" . $plainQuoted;

        return ['html' => $html, 'plain' => $plain];
    }
}
