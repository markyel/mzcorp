<?php

namespace App\Services\Mail;

use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Models\Mailbox;
use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Детектор внутренних отправителей.
 *
 * Бизнес-кейс: M-2026-0161 — наш сотрудник `alexander.rodenkov@myzip.ru`
 * прислал внутреннее сообщение в общий ящик `mail@myzip.ru`, gpt-4o
 * категоризовал его как `client_request`, IncomingMailProcessor создал
 * Request, AssignmentService назначил менеджера. Запись бессмысленная —
 * это внутренняя переписка, не клиентская заявка.
 *
 * Логика: from_email относится к нам, если хотя бы одно из:
 *   - домен совпадает с `config('services.mail.internal_domains')`
 *     (default `['myzip.ru']`);
 *   - email совпадает (case-i) с любым `Mailbox.email`
 *     (наши OAuth-подключённые ящики);
 *   - email совпадает (case-i) с любым `User.email`
 *     (наши пользователи системы).
 *
 * Используется в `MailCategoryClassifier::categorize()` как pre-classifier
 * short-circuit ДО LLM-вызова: если внутренний — принудительно `irrelevant`,
 * confidence=1.0. Никакой LLM не нужен — это детерминированно.
 */
class InternalSenderDetector
{
    /**
     * @return string|null  Причина (`domain:myzip.ru` / `mailbox` / `user`)
     *                       или null если отправитель внешний.
     */
    public function detect(EmailMessage $message): ?string
    {
        $from = mb_strtolower(trim((string) $message->from_email));
        if ($from === '') {
            return null;
        }

        // 0. Allowlist — техническая автоматика на нашем домене, которая
        // не является «сотрудником» и должна пропускаться дальше
        // (категоризатор сам решит client_request / irrelevant).
        // Типовой кейс: order@myzip.ru — web-form ящик сайта, шлёт заявки
        // клиентов через нашу почту. Без allowlist'а doman match банил их
        // как «внутренние», заявки терялись.
        if ($this->inAllowlist($from)) {
            return null;
        }

        return $this->internalReason($from);
    }

    /**
     * Должно ли inbound-письмо влиять на статус/позиции заявки. Правило
     * (по требованию заказчика): влияют только письма, где заказчик на одной
     * из сторон (отправитель ИЛИ получатель). Внутренняя переписка сотрудников
     * — нет, даже если в CC стоит личный внешний адрес коллеги.
     *
     *   - Отправитель ВНЕШНИЙ → влияет (это заказчик или его коллега).
     *   - Отправитель НАШ (сотрудник) → влияет ТОЛЬКО если заказчик заявки
     *     (clientEmail) явно среди получателей to/cc. Иначе это внутреннее
     *     общение про заявку — не трогаем.
     *
     * Кейс M-2026-6071: письмо руководителя менеджеру (from/to @myzip.ru, в CC
     * личный markyellow@yandex.ru — не заказчик) авто-переводило «КП отправлено»
     * → «На согласовании». clientEmail пуст → false (не можем подтвердить
     * заказчика при нашем отправителе → безопаснее не трогать статус).
     */
    public function affectsRequestStatus(EmailMessage $message, ?string $clientEmail): bool
    {
        $from = mb_strtolower(trim((string) $message->from_email));
        $client = mb_strtolower(trim((string) $clientEmail));

        // Внешний отправитель — обычно заказчик (или его коллега/альт-адрес).
        if ($this->detect($message) === null) {
            // НО «ответить всем» от ТРЕТЬЕЙ СТОРОНЫ — тоже внешний. Кейс
            // M-2026-11446: клиент отправил запрос нам И конкуренту
            // (a.petrishev@nlp-group.ru); конкурент ответил всем «Нет такого у
            // нас» → детектор прочитал как ОТКАЗ КЛИЕНТА и авто-закрыл заявку.
            // Признак третьей стороны: отправитель НЕ заказчик (ни email, ни
            // домен) И заказчик среди ПОЛУЧАТЕЛЕЙ (т.е. отправитель адресует
            // заказчику, а не является им). Такое письмо статус не трогает.
            if ($client !== ''
                && ! $this->isSameParty($from, $client)
                && in_array($client, $this->recipientEmails($message), true)) {
                return false;
            }

            return true;
        }

        // Отправитель наш → влияет только если заказчик среди получателей.
        if ($client === '') {
            return false;
        }

        return in_array($client, $this->recipientEmails($message), true);
    }

    /**
     * Адресовано ли ИСХОДЯЩЕЕ письмо заказчику заявки. Гард для outbound
     * document detector (КП/счёт/уточнение/отказ): документ клиенту может
     * уйти только клиенту — внутренний пересыл коллеге или третьей стороне
     * статус заявки двигать не должен.
     *
     * Кейс M-2026-14608: письмо клиента «Прошу выставить счёт» + PDF
     * «Реквизиты ООО …» переслали из info@ на сторонний адрес с комментарием
     * «Этой заявки нет в мз корпе». Пересыл унаследовал In-Reply-To →
     * прилинковался к заявке → LLM-классификатор прочитал цитату клиента
     * и имя файла как «счёт» → заявка ушла в «Счёт отправлен» без счёта.
     *
     * Правило: среди to/cc есть адрес заказчика — точный, либо на том же
     * КОРПОРАТИВНОМ домене (коллега заказчика), либо адрес, который САМ писал
     * в эту заявку (второй контакт клиента: снабженец, бухгалтерия). Для
     * публичных почтовиков (gmail.com, mail.ru, …) совпадение домена не
     * считается — там «тот же домен» ничего не значит. Внутренние домены
     * (наши) тоже не считаются. Если e-mail заказчика в заявке не заполнен —
     * подтвердить нечем, возвращаем true (детектор работает как раньше).
     *
     * Кейс M-2026-14316: заявка пришла с rodionshvedchikov@yandex.ru, счёт
     * менеджер отправил на vershina2004@yandex.ru — второй адрес того же
     * заказчика, с которого он и попросил счёт в этом же треде. Оба на
     * yandex.ru, поэтому домен не спасал: детектор молчал, статус остался
     * «ждёт счёт», счёт не попал в раздел «Счета». Участие адреса в треде
     * заявки третью сторону не пропускает — пересыл из 14608 уходил на
     * адрес, который в заявку никогда не писал.
     */
    public function isAddressedToClient(EmailMessage $message, ?string $clientEmail, ?Request $request = null): bool
    {
        $client = mb_strtolower(trim((string) $clientEmail));
        if ($client === '') {
            return true;
        }

        $recipients = $this->recipientEmails($message);
        if (in_array($client, $recipients, true)) {
            return true;
        }

        if ($recipients !== [] && $this->wroteIntoRequest($recipients, $request, $message)) {
            return true;
        }

        $clientDomain = (string) substr((string) strrchr($client, '@'), 1);
        if ($clientDomain === ''
            || $this->isPublicMailDomain($clientDomain)
            || $this->isInternalDomain($clientDomain)) {
            return false;
        }

        foreach ($recipients as $rcpt) {
            if (str_ends_with($rcpt, '@' . $clientDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Писал ли кто-то из получателей САМ в эту заявку. Признак «второй контакт
     * клиента» (снабженец, бухгалтерия, коллега с личной почты): его входящее
     * письмо уже привязано к заявке, значит и документ ему — документ клиенту.
     * Третья сторона из кейса M-2026-14608 такой проверки не проходит: на тот
     * адрес только пересылали, входящих от него в заявке нет.
     *
     * @param  list<string>  $recipients
     */
    protected function wroteIntoRequest(array $recipients, ?Request $request, EmailMessage $message): bool
    {
        $requestId = $request?->id ?? $message->related_request_id;
        if (! $requestId) {
            return false;
        }

        return EmailMessage::query()
            ->where('related_request_id', $requestId)
            ->where('direction', MailDirection::Inbound)
            ->whereIn(DB::raw('lower(from_email)'), $recipients)
            ->exists();
    }

    private function isPublicMailDomain(string $domain): bool
    {
        $domains = array_filter(array_map(
            fn ($d) => mb_strtolower(trim((string) $d)),
            (array) config('services.mail.public_mail_domains', [])
        ));

        return in_array(mb_strtolower($domain), $domains, true);
    }

    private function isInternalDomain(string $domain): bool
    {
        $domains = array_filter(array_map(
            fn ($d) => mb_strtolower(trim((string) $d)),
            (array) config('services.mail.internal_domains', [])
        ));

        return in_array(mb_strtolower($domain), $domains, true);
    }

    /** Один контрагент: совпадает точный e-mail или домен. */
    private function isSameParty(string $a, string $b): bool
    {
        $a = mb_strtolower(trim($a));
        $b = mb_strtolower(trim($b));
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $da = (string) substr((string) strrchr($a, '@'), 1);
        $db = (string) substr((string) strrchr($b, '@'), 1);

        return $da !== '' && $da === $db;
    }

    /**
     * @return array<int, string> lowercased to+cc адреса
     */
    private function recipientEmails(EmailMessage $message): array
    {
        $out = [];
        foreach ([$message->to_recipients, $message->cc_recipients] as $bag) {
            foreach ((array) $bag as $r) {
                $email = mb_strtolower(trim((string) (is_array($r) ? ($r['email'] ?? '') : $r)));
                if ($email !== '') {
                    $out[] = $email;
                }
            }
        }

        return $out;
    }

    private function inAllowlist(string $email): bool
    {
        $allowlist = array_filter(array_map(
            fn ($e) => mb_strtolower(trim((string) $e)),
            (array) config('services.mail.internal_sender_allowlist', [])
        ));

        return in_array($email, $allowlist, true);
    }

    /**
     * Причина, по которой адрес считается нашим (`domain:x` / `mailbox` /
     * `user`), либо null если внешний. Allowlist здесь НЕ учитывается —
     * вызывающий решает сам (detect короткозамыкает, isInternalOnly трактует
     * allowlist-адрес как внешнюю сторону).
     */
    private function internalReason(string $email): ?string
    {
        // 1. Domain match.
        $domains = (array) config('services.mail.internal_domains', []);
        foreach ($domains as $d) {
            $d = mb_strtolower(trim((string) $d));
            if ($d === '') {
                continue;
            }
            if (str_ends_with($email, '@' . $d)) {
                return 'domain:' . $d;
            }
        }

        // 2. Mailbox match (наши OAuth-подключённые ящики).
        if (Mailbox::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return 'mailbox';
        }

        // 3. User match (наши пользователи системы — кто-то из коллег).
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return 'user';
        }

        return null;
    }
}
