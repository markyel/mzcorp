<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;

/**
 * Разбор ПЕРЕСЛАННЫХ заявок.
 *
 * Иногда клиентское письмо-заявку вручную пересылают на info@ с технического
 * ящика noreply@myzip.ru (или иного из forwarder_senders). Тогда
 * EmailMessage.from_email = noreply@myzip.ru, а реальный отправитель указан в
 * блоке пересылки в теле:
 *
 *   -------- Перенаправленное сообщение --------
 *   Тема:    Коммерческое предложение
 *   Дата:    Thu, 18 Jun 2026 16:00:16 +0300
 *   От:      Ладошин Александр Игоревич <ladoshin@lemuslift.ru>
 *   Кому:    МойЗип <noreply@myzip.ru>
 *
 * Парсер достаёт реального отправителя (e-mail + имя) из строки «От:»/«From:»,
 * чтобы Request.client_email указывал на клиента, а ответы шли ему, а не на
 * технический ящик. Аналог WebFormSubmissionParser (заявки с сайта).
 */
class ForwardedRequestParser
{
    /**
     * Ящики-форвардеры. С них письмо считается пересланной заявкой.
     *
     * @return list<string> lowercase
     */
    public function forwarderSenders(): array
    {
        return array_values(array_filter(array_map(
            fn ($e) => mb_strtolower(trim((string) $e)),
            (array) config('services.mail.forwarder_senders', []),
        )));
    }

    /**
     * Письмо пришло с ящика-форвардера?
     */
    public function isForwarded(EmailMessage $message): bool
    {
        $from = mb_strtolower(trim((string) $message->from_email));

        return $from !== '' && in_array($from, $this->forwarderSenders(), true);
    }

    /**
     * Достать реального отправителя из блока пересылки в теле.
     *
     * @return array{email: string, name: ?string}|null null — блок не найден
     *                                                  или e-mail невалиден / внутренний.
     */
    public function parse(EmailMessage $message): ?array
    {
        $text = $this->bodyText($message);
        if ($text === '') {
            return null;
        }

        // Строка отправителя в блоке пересылки: «От:», «От кого:»,
        // «Отправитель:», «From:», «Sender:» (НЕ «Отвечать:»/«Кому:» — у них
        // другие лейблы). Берём первое совпадение — внешний оригинальный
        // отправитель (на случай вложенных пересылок).
        if (! preg_match(
            '/(?:^|\n)[ \t>]*(?:Отправитель|От\s+кого|От|From|Sender)[ \t]*:[ \t]*([^\r\n]+)/iu',
            $text,
            $m,
        )) {
            return null;
        }
        $value = trim($m[1]);

        $email = null;
        $name = null;
        // Приоритет — адрес в угловых скобках «Имя <e-mail>».
        if (preg_match('/<\s*([^<>\s@]+@[^<>\s@]+)\s*>/u', $value, $em)) {
            $email = trim($em[1]);
            $name = trim((string) preg_replace('/<[^>]*>/u', '', $value), " \t\"'");
        } elseif (preg_match('/([^\s<>@"\']+@[^\s<>@"\']+\.[^\s<>@"\']+)/u', $value, $em)) {
            $email = trim($em[1]);
            $name = trim(str_replace($email, '', $value), " \t\"'<>");
        }
        if ($email === null) {
            return null;
        }

        $email = mb_strtolower($email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        // Защита: не подменяем на наш же/внутренний адрес (если парс зацепил не
        // ту строку, напр. «Кому: <noreply@myzip.ru>»). Реальный клиент внешний.
        if ($this->isInternalEmail($email)) {
            return null;
        }

        return [
            'email' => $email,
            'name' => ($name !== null && $name !== '') ? mb_substr($name, 0, 255) : null,
        ];
    }

    /**
     * Текст письма для разбора блока пересылки: plain приоритетно (там и лежат
     * заголовки пересылки), иначе — стрипнутый HTML.
     */
    private function bodyText(EmailMessage $message): string
    {
        $plain = trim((string) $message->body_plain);
        if ($plain !== '') {
            return $plain;
        }
        $html = (string) $message->body_html;
        if ($html === '') {
            return '';
        }

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Адрес на нашем (внутреннем) домене — не клиентский.
     */
    private function isInternalEmail(string $email): bool
    {
        foreach ((array) config('services.mail.internal_domains', []) as $d) {
            $d = mb_strtolower(trim((string) $d));
            if ($d !== '' && str_ends_with($email, '@'.$d)) {
                return true;
            }
        }

        return false;
    }
}
