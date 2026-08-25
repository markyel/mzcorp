<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;

/**
 * Разбор заявок с сайта myzip.ru.
 *
 * Сайт шлёт заявку на info@ с технического ящика order@myzip.ru — поэтому
 * EmailMessage.from_email = order@myzip.ru, а реальный клиент указан в теле.
 * Тело — фиксированный HTML:
 *
 *   <b>Заказ: 233</b><br>
 *   Организация: <i>Стакс-плюс</i><br>
 *   Адрес: <i>пр. Славы, 51</i><br>
 *   Контактное лицо: <i>Андрей</i><br>
 *   Телефон: <i>89119973010</i><br>
 *   E-mail: <a href='mailto:andrei-pudovikov@yandex.ru'><i>...</i></a><br>
 *   <table>…позиции…</table>
 *
 * Парсер извлекает реальные контакты, чтобы Request.client_email указывал на
 * клиента (а не на технический ящик), и переписка/уведомления шли туда же.
 * Позиции (таблица) парсит штатный ParseRequestItemsJob — здесь только шапка.
 */
class WebFormSubmissionParser
{
    /**
     * Отправители-релеи веб-формы. По ним письмо считается заявкой с сайта.
     *
     * @return list<string> lowercase
     */
    public function relaySenders(): array
    {
        return array_values(array_filter(array_map(
            fn ($e) => mb_strtolower(trim((string) $e)),
            (array) config('services.mail.web_form_senders', []),
        )));
    }

    /**
     * Письмо — заявка с сайта (пришло с релей-ящика веб-формы)?
     */
    public function isWebFormSubmission(EmailMessage $message): bool
    {
        $from = mb_strtolower(trim((string) $message->from_email));
        if ($from !== '' && in_array($from, $this->relaySenders(), true)) {
            return true;
        }

        // Вторая форма «Вопрос с сайта MyZip»: приходит с info@ (наш ящик),
        // отличается display-name «Веб сайт» + темой. По from_email не ловится.
        $fromName = mb_strtolower(trim((string) $message->from_name));
        $nameMarkers = array_map('mb_strtolower', (array) config('services.mail.web_form_name_markers', []));
        if ($fromName !== '' && in_array($fromName, $nameMarkers, true)) {
            return true;
        }
        $subject = mb_strtolower(trim((string) $message->subject));
        foreach ((array) config('services.mail.web_form_subject_markers', []) as $marker) {
            $marker = mb_strtolower(trim((string) $marker));
            if ($marker !== '' && str_contains($subject, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Извлечь контакты клиента из тела веб-формы.
     *
     * @return array{email: ?string, name: ?string, phone: ?string, company: ?string, address: ?string}|null
     *         null — если письмо не похоже на веб-форму или нет валидного email.
     */
    public function parse(EmailMessage $message): ?array
    {
        $html = (string) $message->body_html;
        if ($html === '') {
            return null;
        }

        // E-mail — приоритетно из mailto: (надёжнее текста), затем из метки.
        $email = null;
        if (preg_match('/mailto:([^"\'>\s]+@[^"\'>\s]+)/i', $html, $m)) {
            $email = trim($m[1]);
        }

        // <br> → перенос строки ДО strip_tags: формы без block-тегов (вторая
        // форма «Вопрос с сайта» — item<br><br>имя<br>email) иначе схлопывались
        // бы в одну строку и построчный разбор/эвристики не работали.
        $textHtml = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($textHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($email === null) {
            $email = $this->field($text, ['E-?mail', 'Почта', 'Эл\.?\s*почта']);
        }

        // Вторая форма: e-mail без mailto и без метки — просто голый адрес в
        // теле. Берём первый ВНЕШНИЙ (не наш домен) e-mail из текста.
        $emailFromBareLine = false;
        if ($email === null) {
            $email = $this->firstExternalEmail($text);
            $emailFromBareLine = $email !== null;
        }

        $email = $email !== null ? mb_strtolower(trim($email)) : null;
        if ($email === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Без валидного клиентского email подмена смысла не имеет.
            return null;
        }

        $name = $this->field($text, ['Контактное лицо', 'Контакт', 'ФИО', 'Имя']);
        // Вторая форма без меток: имя — непустая строка НЕПОСРЕДСТВЕННО перед
        // строкой с e-mail (кейс «Виталий\n9161679434@mail.ru»).
        if ($name === null && $emailFromBareLine) {
            $name = $this->nameBeforeEmail($text, $email);
        }

        return [
            'email' => $email,
            'name' => $name,
            'phone' => $this->field($text, ['Телефон', 'Тел\.?', 'Phone']),
            'company' => $this->field($text, ['Организация', 'Компания', 'Заказчик']),
            'address' => $this->field($text, ['Адрес']),
        ];
    }

    /**
     * Первый e-mail в тексте, НЕ принадлежащий нашим внутренним доменам
     * (info@myzip.ru, marketing@myzip.ru в получателях — не клиент).
     */
    private function firstExternalEmail(string $text): ?string
    {
        if (! preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', $text, $mm)) {
            return null;
        }
        $internal = array_map('mb_strtolower', array_merge(
            (array) config('services.mail.internal_domains', []),
            ['mzcorp.ru'],
        ));
        foreach ($mm[0] as $candidate) {
            $domain = mb_strtolower((string) \Illuminate\Support\Str::afterLast($candidate, '@'));
            if ($domain !== '' && ! in_array($domain, $internal, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Имя из строки непосредственно перед строкой с указанным e-mail.
     */
    private function nameBeforeEmail(string $text, string $email): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/u', $text) ?: [];
        $emailLc = mb_strtolower($email);
        $prev = null;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (mb_strtolower($trimmed) !== '' && str_contains(mb_strtolower($trimmed), $emailLc)) {
                // предыдущая непустая строка — имя (если это не строка позиций
                // с двоеточием/цифрами-артикулами — тогда пропускаем).
                if ($prev !== null && ! str_contains($prev, ':') && mb_strlen($prev) <= 120) {
                    return mb_substr($prev, 0, 255);
                }

                return null;
            }
            if ($trimmed !== '') {
                $prev = $trimmed;
            }
        }

        return null;
    }

    /**
     * Достать значение поля по одной из меток: «Метка: значение» до конца строки.
     *
     * @param  list<string>  $labels  regex-фрагменты меток (без экранирования двоеточия)
     */
    private function field(string $text, array $labels): ?string
    {
        foreach ($labels as $label) {
            // После двоеточия — только ГОРИЗОНТАЛЬНЫЕ пробелы ([^\S\r\n]*), не
            // \s*: иначе при пустом поле («Адрес:\n») регекс перескакивал через
            // перевод строки и тащил значение следующего поля (Контактное лицо).
            if (preg_match('/' . $label . '[^\S\r\n]*:[^\S\r\n]*([^\r\n]+)/iu', $text, $m)) {
                $value = trim($m[1]);
                if ($value !== '') {
                    return mb_substr($value, 0, 255);
                }
            }
        }

        return null;
    }
}
