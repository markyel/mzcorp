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
