<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;

/**
 * Сборка quoted-блока оригинального письма для reply (Phase 1.9).
 *
 * Markup такой же как у Gmail / Yandex.Mail — без него почтовые клиенты
 * НЕ распознают цитату как collapsible (троеточие «…»):
 *   <div class="gmail_quote gmail_quote_container">
 *     <div dir="ltr" class="gmail_attr">On DATE, NAME &lt;EMAIL&gt; wrote:<br></div>
 *     <blockquote class="gmail_quote" style="margin:0 0 0 0.8ex;...">
 *       {original body}
 *     </blockquote>
 *   </div>
 *
 * Ключевое:
 *   - attribution-line ВНЕ blockquote (внутри клиенты его игнорируют);
 *   - blockquote с class="gmail_quote" + точные inline-styles из gmail;
 *   - один пустой div перед wrapper'ом — gmail-конвенция «отступ от ответа».
 *
 * Plain-вариант: «On … wrote:» + `> `-prefix к каждой строке оригинала —
 * стандартное RFC quoting, любой клиент сворачивает.
 */
class MailQuoteBuilder
{
    /**
     * @return array{html: string, plain: string}
     */
    public function build(EmailMessage $replyTo): array
    {
        $date = $replyTo->sent_at?->toDateTimeString() ?? '';
        $fromEmail = (string) $replyTo->from_email;
        $fromName = trim((string) ($replyTo->from_name ?? ''));

        $headerPlain = sprintf(
            'On %s, %s <%s> wrote:',
            $date,
            $fromName !== '' ? $fromName : $fromEmail,
            $fromEmail,
        );

        // Attribution-line для HTML — со ссылкой на mailto, как у Gmail.
        $emailEsc = htmlspecialchars($fromEmail, ENT_QUOTES, 'UTF-8');
        $nameEsc = htmlspecialchars(
            $fromName !== '' ? $fromName : $fromEmail,
            ENT_QUOTES,
            'UTF-8'
        );
        $dateEsc = htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
        $headerHtml = sprintf(
            'On %s, %s &lt;<a href="mailto:%s">%s</a>&gt; wrote:<br>',
            $dateEsc,
            $nameEsc,
            $emailEsc,
            $emailEsc,
        );

        $originalHtml = (string) ($replyTo->body_html ?: nl2br(htmlspecialchars(
            (string) $replyTo->body_plain,
            ENT_QUOTES,
            'UTF-8'
        )));

        $html = '<br><div class="gmail_quote gmail_quote_container">'
            . '<div dir="ltr" class="gmail_attr">' . $headerHtml . '</div>'
            . '<blockquote class="gmail_quote" '
            . 'style="margin:0px 0px 0px 0.8ex;border-left:1px solid rgb(204,204,204);padding-left:1ex">'
            . $originalHtml
            . '</blockquote>'
            . '</div>';

        $originalPlain = (string) ($replyTo->body_plain ?: strip_tags((string) $replyTo->body_html));
        $plainQuoted = preg_replace('/^/m', '> ', $originalPlain);
        $plain = "\n" . $headerPlain . "\n" . $plainQuoted;

        return ['html' => $html, 'plain' => $plain];
    }
}
