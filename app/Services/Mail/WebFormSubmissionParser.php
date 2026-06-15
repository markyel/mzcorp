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

        return $from !== '' && in_array($from, $this->relaySenders(), true);
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

        // Текст без тегов + декод сущностей — для меток поля построчно.
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($email === null) {
            $email = $this->field($text, ['E-?mail', 'Почта', 'Эл\.?\s*почта']);
        }

        $email = $email !== null ? mb_strtolower(trim($email)) : null;
        if ($email === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Без валидного клиентского email подмена смысла не имеет.
            return null;
        }

        return [
            'email' => $email,
            'name' => $this->field($text, ['Контактное лицо', 'Контакт', 'ФИО', 'Имя']),
            'phone' => $this->field($text, ['Телефон', 'Тел\.?', 'Phone']),
            'company' => $this->field($text, ['Организация', 'Компания', 'Заказчик']),
            'address' => $this->field($text, ['Адрес']),
        ];
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
