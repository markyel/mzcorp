<?php

namespace App\Services\QuoteParts;

/**
 * Лента переписки с одним поставщиком по запросу QuoteParts из снимка запроса: наши письма
 * (запрос, напоминания), его ответы и наши ответы ему, события — по времени.
 */
final class QuotePartsThread
{
    /**
     * @param  array<string, mixed>  $supplier  поставщик из snapshot['suppliers']
     * @return list<array<string, mixed>>
     */
    public static function of(array $supplier): array
    {
        $s = $supplier;

        return collect($s['letters'] ?? [])->map(fn ($l) => ['direction' => 'out', 'kind' => 'letter', 'at' => $l['at'] ?? null, 'who' => ! empty($l['to']) ? 'кому: '.$l['to'] : null, 'subject' => $l['subject'] ?? null, 'text' => (string) ($l['text'] ?? ''), 'restored' => $l['restored'] ?? false, 'error' => $l['error'] ?? null, 'files' => []])
            ->concat($s['thread'] ?? [])
            ->when(! empty($s['reminders_sent']), fn ($c) => $c->push(['direction' => 'event', 'at' => $s['last_reminder_at'] ?? null, 'text' => 'QuoteParts отправил напоминание'.($s['reminders_sent'] > 1 ? ' (всего '.$s['reminders_sent'].')' : '').' по позициям без ответа']))
            ->when(! empty($s['opened_at']), fn ($c) => $c->push(['direction' => 'event', 'at' => $s['opened_at'], 'text' => 'Поставщик открыл форму запроса']))
            ->when(! empty($s['sent_at']), fn ($c) => $c->push(['direction' => 'event', 'at' => $s['sent_at'], 'text' => 'Запрос отправлен через QuoteParts']))
            ->sortBy(fn ($m) => $m['at'] ?? '')->values()->all();
    }

    /**
     * Письмо на «суть» и «хвост» для пузыря ленты: суть — свой текст без подписи и цитаты, с
     * переносами строк (свёрнутый пузырь показывает её начало); хвост — подпись и цитата,
     * дописываются ниже при «весь текст». Так развёрнутый текст не перестраивается.
     *
     * @return array{0: string, 1: string} [суть, хвост]
     */
    public static function split(string $text): array
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        $cleaner = app(\App\Services\Mail\EmailTextCleanerService::class);
        $own = $cleaner->removeSignature($cleaner->cutQuotedReplyTail($text));
        // Пустые строки у почтовиков бывают из неразрывных пробелов (\h их ловит): хвосты строк и лишние пустые — вон.
        $tidy = fn (string $s) => preg_replace('/^\s+|\s+$/u', '', preg_replace("/\n{3,}/u", "\n\n", preg_replace('/\h+$/mu', '', $s) ?? $s) ?? $s) ?? $s;
        if ($own === '' || ! str_starts_with($text, $own)) {
            return [$tidy($text), ''];
        }

        return [$tidy($own), $tidy(mb_substr($text, mb_strlen($own)))];
    }

    /**
     * Суть письма для компактной ленты: без цитаты прошлых писем и подписи, в одну строку,
     * не длиннее $limit. Если после чистки ничего не осталось — начало исходного текста.
     */
    public static function gist(string $text, int $limit = 220): string
    {
        $cleaner = app(\App\Services\Mail\EmailTextCleanerService::class);
        $own = $cleaner->removeSignature($cleaner->cutQuotedReplyTail($text));
        $line = trim(preg_replace('/\s+/u', ' ', $own !== '' ? $own : $text) ?? '');

        return \Illuminate\Support\Str::limit($line, $limit);
    }
}
