<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;

/**
 * Сборка quoted-блока оригинального письма для reply (Phase 1.9).
 *
 * Формат — Apple Mail RU («26 апр. 2026 г., в 11:27, Имя <email> написал(а):»),
 * это эталон который Yandex Web UI / Mail.ru / Apple Mail / Outlook RU
 * распознают и сворачивают «троеточием».
 *
 * Markup (точная копия того что генерирует Apple Mail при reply):
 *   <div>26 апр. 2026 г., в 11:27, Имя &lt;email&gt; написал(а):<br></div>
 *   <blockquote type="cite" style="margin: 0 0 0 .8ex; border-left: 1px #ccc solid; padding-left: 1ex;">
 *     {original body}
 *   </blockquote>
 *
 * Gmail-формат `On DATE, NAME wrote:` Yandex НЕ свeрнёт через троеточие —
 * проверено эмпирически. RU-локализованный attribution с «написал(а):»
 * для нашей аудитории (русскоязычные клиенты) надёжнее.
 *
 * Plain-вариант: `> ` префикс к каждой строке — RFC 5322 standard,
 * любой клиент сворачивает.
 */
class MailQuoteBuilder
{
    /** @var array<int, string> Apple Mail RU short month names. */
    private const RU_MONTHS = [
        1 => 'янв.', 2 => 'февр.', 3 => 'мар.', 4 => 'апр.', 5 => 'мая',
        6 => 'июн.', 7 => 'июл.', 8 => 'авг.', 9 => 'сент.',
        10 => 'окт.', 11 => 'нояб.', 12 => 'дек.',
    ];

    /**
     * @return array{html: string, plain: string}
     */
    public function build(EmailMessage $replyTo): array
    {
        $fromEmail = (string) $replyTo->from_email;
        $fromName = trim((string) ($replyTo->from_name ?? ''));
        $displayName = $fromName !== '' ? $fromName : $fromEmail;

        $attributionText = $this->buildAttributionLine($replyTo, $displayName, $fromEmail);

        $emailEsc = htmlspecialchars($fromEmail, ENT_QUOTES, 'UTF-8');
        $nameEsc = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
        $dateEsc = htmlspecialchars($this->formatAppleRuDate($replyTo), ENT_QUOTES, 'UTF-8');

        $attributionHtml = sprintf(
            '%s, %s &lt;<a href="mailto:%s">%s</a>&gt; написал(а):',
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

        $html = '<br><div>' . $attributionHtml . '<br></div>'
            . '<blockquote type="cite" '
            . 'style="margin: 0 0 0 .8ex; border-left: 1px #ccc solid; padding-left: 1ex;">'
            . $originalHtml
            . '</blockquote>';

        $originalPlain = (string) ($replyTo->body_plain ?: strip_tags((string) $replyTo->body_html));
        $plainQuoted = preg_replace('/^/m', '> ', $originalPlain);
        $plain = "\n" . $attributionText . "\n" . $plainQuoted;

        return ['html' => $html, 'plain' => $plain];
    }

    private function buildAttributionLine(EmailMessage $replyTo, string $displayName, string $fromEmail): string
    {
        return sprintf(
            '%s, %s <%s> написал(а):',
            $this->formatAppleRuDate($replyTo),
            $displayName,
            $fromEmail,
        );
    }

    /**
     * «26 апр. 2026 г., в 11:27» — формат Apple Mail RU.
     */
    private function formatAppleRuDate(EmailMessage $replyTo): string
    {
        $date = $replyTo->sent_at;
        if (! $date) {
            return '';
        }
        $month = self::RU_MONTHS[(int) $date->month] ?? '';

        return sprintf(
            '%d %s %d г., в %02d:%02d',
            $date->day,
            $month,
            $date->year,
            $date->hour,
            $date->minute,
        );
    }
}
