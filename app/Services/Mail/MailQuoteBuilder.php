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
        $originalHtml = $this->sanitizeQuotedHtml($originalHtml);

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
     * Изоляция цитируемого HTML от внешних писем.
     *
     * Письма из шаблонизаторов (Liftway, Mailchimp, корп-системы) часто
     * приходят как «полный документ»: `<html><head><style>blockquote{…}</style>
     * <style>* { … }</style></head><body>…</body></html>`. Если положить такое
     * целиком внутрь нашего `<blockquote type="cite" …>`, происходит две
     * проблемы:
     *   1. `<style>` каскадирует на ВЕСЬ родительский iframe — переопределяет
     *      нашу рамку blockquote, шрифты, поля. В CRM-треде цитата визуально
     *      перестаёт отличаться от тела письма («продолжение»).
     *   2. Apple Mail / Yandex Web UI при наличии вложенного `<html>`/`<head>`
     *      перестают распознавать blockquote как quoted text и не сворачивают
     *      цитату троеточием.
     *
     * Распаковываем `<body>…</body>` если оригинал — full doc, и срезаем
     * `<style>`, `<script>`, `<head>`, `<html>`, `<body>`-теги (атрибуты
     * `style="…"` остаются — это inline-стили, они безопасны и нужны для
     * сохранения вида КП-плашек и таблиц).
     */
    private function sanitizeQuotedHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // Выдрать содержимое <body>...</body> если это полный документ.
        if (preg_match('/<body\b[^>]*>(.*?)<\/body>/is', $html, $m)) {
            $html = $m[1];
        }

        // Срезать <head>...</head> если каким-то образом дошёл (без body).
        $html = preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $html) ?? $html;

        // Срезать <style>, <script>, <link>, <meta> вместе с содержимым.
        $html = preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<(link|meta)\b[^>]*\/?>/i', '', $html) ?? $html;

        // Срезать обёрточные теги документа (оставляем содержимое).
        $html = preg_replace('/<\/?(html|body)\b[^>]*>/i', '', $html) ?? $html;

        return trim($html);
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
