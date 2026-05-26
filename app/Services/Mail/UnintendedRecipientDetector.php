<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\User;

/**
 * Детектор «мы не получатель этого письма».
 *
 * Бизнес-кейс: M-2026-1491 — `info@unisystem.si` написал
 * `valentina.larosa@moris.it` (двое внешних), наш `Andrey.Vasukhno@myzip.ru`
 * был добавлен как BCC (или forward со стороны Yandex / mailing list).
 * В видимых получателях нас нет, but письмо легло в наш ящик. gpt-4o
 * увидел `Re: Request (...)` + 6 фото + текст «identify and quote» —
 * confidence=0.95 client_request. Заявка-фантом.
 *
 * Логика «unintended recipient»:
 *   1. НИ ОДИН адрес из to_recipients + cc_recipients не наш
 *      (домен из internal_domains / Mailbox.email / User.email).
 *   2. И тред нам неизвестен: либо in_reply_to пустой, либо
 *      referenced Message-ID нет в нашей БД.
 *
 * Если оба условия выполнены → возвращаем строку-причину.
 *
 * Почему нельзя просто «нас нет в To/Cc → irrelevant»:
 * легитимные BCC бывают — клиент намеренно ставит нас в скрытую копию,
 * когда тред уже идёт. В этом случае in_reply_to укажет на наш ранее
 * сохранённый Message-ID, и мы продолжим обработку как обычно.
 *
 * Используется в `MailCategoryClassifier::categorize()` как pre-classifier
 * short-circuit ДО LLM-вызова — детерминированно `irrelevant`, confidence=1.0,
 * без затрат на gpt-4o.
 */
class UnintendedRecipientDetector
{
    /**
     * @return string|null Причина (например `not_in_to_cc + unknown_thread`)
     *                     или null если письмо адресовано нам или связано с
     *                     нашим тредом.
     */
    public function detect(EmailMessage $message): ?string
    {
        if ($this->anyRecipientIsOurs($message)) {
            return null;
        }

        if ($this->repliesToKnownThread($message)) {
            return null;
        }

        // BCC-рассылка от клиента нескольким поставщикам — два паттерна:
        //
        //   (а) все видимые получатели в to/cc — псевдо-адреса
        //       (`undisclosed-recipients:;` / group-syntax без членов /
        //       просто не-email). MUA пишет такое когда ВСЕ реальные
        //       адреса в BCC. Кейсы: meteor.ru OGrigorieva / AMustafin.
        //
        //   (б) клиент ставит САМОГО СЕБЯ в `to` (или в cc), а реальных
        //       получателей в BCC. Тогда `from == to`. MUA так делает у
        //       клиентов, которые «отправляют письмо себе и в BCC всем
        //       поставщикам». Кейс: modtfil.abasov@mail.ru → himself.
        //
        // Оба паттерна — легитимная клиентская заявка (типовая практика:
        // клиент шлёт «прошу цены и сроки» сразу 5 поставщикам, скрывая
        // конкурентов). Bypass'им детектор и отдаём решение LLM-классификатору.
        //
        // Грань с M-2026-1491 сохранена: там to содержал реальный email
        // ТРЕТЬЕГО лица (`valentina.larosa@moris.it`, ≠ from), и from с to
        // разные. Этот случай продолжает ловиться unintended-веткой.
        if ($this->looksLikeBccBlast($message)) {
            return null;
        }

        return 'not_in_to_cc + unknown_thread';
    }

    /**
     * Письмо похоже на BCC-рассылку клиента нескольким поставщикам.
     *
     * Два независимых сигнала:
     *   (а) поле `to` пусто или состоит ТОЛЬКО из псевдо-адресов
     *       (нет `@`, или `undisclosed-recipients`, или `…:;`).
     *       CC игнорируется — там бывают коллеги отправителя
     *       (для info), что не делает письмо адресованным «не нам».
     *       Кейс at@stein.ru: to=<undisclosed-recipients:;>,
     *       cc=av@stein.ru (Виноградов — коллега Березовского).
     *       Раньше один реальный email в CC закрывал детектор и
     *       заявка терялась как «unintended».
     *   (б) `from_email` присутствует в to/cc — клиент пишет себе
     *       и в скрытую копию поставщикам. Кейс modtfil.abasov.
     */
    private function looksLikeBccBlast(EmailMessage $message): bool
    {
        // (а) только поле `to`.
        $toEmails = $this->emailsFromList((array) $message->to_recipients);
        $hasRealTo = false;
        foreach ($toEmails as $email) {
            if ($this->isRealEmail($email)) {
                $hasRealTo = true;
                break;
            }
        }
        if (!$hasRealTo) {
            return true;
        }

        // (б) отправитель сам в to/cc.
        $emails = $this->collectRecipientEmails($message);
        if ($emails === []) {
            return false;
        }
        $from = mb_strtolower(trim((string) $message->from_email));
        if ($from !== '' && in_array($from, $emails, true)) {
            return true;
        }

        return false;
    }

    private function isRealEmail(string $email): bool
    {
        return str_contains($email, '@')
            && !str_contains($email, 'undisclosed-recipients')
            && !str_ends_with($email, ':;');
    }

    private function anyRecipientIsOurs(EmailMessage $message): bool
    {
        $emails = $this->collectRecipientEmails($message);
        if ($emails === []) {
            return false;
        }

        $domains = array_filter(array_map(
            fn ($d) => mb_strtolower(trim((string) $d)),
            (array) config('services.mail.internal_domains', [])
        ));

        $directMatches = [];

        foreach ($emails as $email) {
            foreach ($domains as $d) {
                if ($d !== '' && str_ends_with($email, '@' . $d)) {
                    return true;
                }
            }
            $directMatches[] = $email;
        }

        if ($directMatches === []) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($directMatches), '?'));

        if (Mailbox::query()->whereRaw("LOWER(email) IN ($placeholders)", $directMatches)->exists()) {
            return true;
        }

        if (User::query()->whereRaw("LOWER(email) IN ($placeholders)", $directMatches)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * @return array<int, string> lower-case e-mails, дедуп
     */
    private function collectRecipientEmails(EmailMessage $message): array
    {
        return array_values(array_unique(array_merge(
            $this->emailsFromList((array) $message->to_recipients),
            $this->emailsFromList((array) $message->cc_recipients),
        )));
    }

    /**
     * @param  array<int, array{email?: string, name?: string}>  $list
     * @return array<int, string> lower-case, дедуп
     */
    private function emailsFromList(array $list): array
    {
        $out = [];
        foreach ($list as $entry) {
            $email = is_array($entry) ? ($entry['email'] ?? null) : null;
            if (! is_string($email)) {
                continue;
            }
            $email = mb_strtolower(trim($email));
            if ($email !== '') {
                $out[$email] = true;
            }
        }

        return array_keys($out);
    }

    private function repliesToKnownThread(EmailMessage $message): bool
    {
        $candidates = [];

        if (is_string($message->in_reply_to) && trim($message->in_reply_to) !== '') {
            $candidates[] = $this->normalize($message->in_reply_to);
        }

        if (is_array($message->references_header)) {
            foreach ($message->references_header as $ref) {
                $clean = $this->normalize((string) $ref);
                if ($clean !== '') {
                    $candidates[] = $clean;
                }
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates)));
        if ($candidates === []) {
            return false;
        }

        return EmailMessage::query()
            ->whereIn('message_id', $candidates)
            ->where('id', '!=', $message->id)
            ->exists();
    }

    private function normalize(string $id): string
    {
        return trim($id, " \t\n\r\0\x0B<>");
    }
}
