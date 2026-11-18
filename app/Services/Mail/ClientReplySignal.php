<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;

/**
 * Передаёт ли входящее письмо в треде заявки ход нам (ClientReplied). Письмо по-прежнему привязывается к заявке и
 * видно в переписке — меняется только то, что заявка не считается «клиент ответил, ждёт нас» (фильтр «Заброшенные»).
 *
 * Не ответ клиента (18.11.2026, разбор «Заброшенных»):
 *  - автоответ: заголовки Auto-Submitted / X-Autoreply, тема или текст «в отпуске», «out of office» (M-2026-21339);
 *  - письмо от нас: коллега (InternalSenderDetector) или служебное уведомление mzCorp, например копия переписки с
 *    поставщиком в ящике менеджера (M-2026-21557, M-2026-21680);
 *  - одно «спасибо» / «получили» без вопроса и вложений — на КП (MZ2026-01278, MZ2026-01449). «Спасибо, ждём счёт»
 *    или «Спасибо, а есть аналог?» — ответ: кроме благодарности, приветствия и имени в письме ничего быть не должно.
 */
class ClientReplySignal
{
    /** Текст, после которого письмо — уже не «просто спасибо»: длиннее — там есть что-то ещё. */
    private const THANKS_MAX_CHARS = 120;

    private const GREETING = '/^(добрый\s+(день|вечер|утро)|доброе\s+утро|здравствуйте|приветствую|привет|hello|hi|dear\s+[\p{L} .]+)[\s,!.]*$/iu';

    private const THANKS = '/^((большое|огромное)\s+)?(спасибо|благодар(ю|им)|thanks?|thank\s+you|many\s+thanks|получил[аи]?|получено|получили|принято|принял[аи]?|ок|ok|okay|хорошо|отлично|понял[аи]?|супер)(\s+(большое|огромное|за\s+(кп|предложение|информацию|ответ|письмо|оперативность)|вам|you|a\s+lot|so\s+much))*[\s,!.)]*$/iu';

    private const AUTO_SUBJECT = '/^\s*(автоответ|автоматический\s+ответ|auto(matic)?[\s-]*reply|out\s+of\s+(the\s+)?office|abwesenheit|自动回复)/iu';

    // От первого лица: «Виктор в отпуске до 1.11. RR24497 Канат…» — коллега клиента отвечает по делу, не автоответ.
    // С начала слова: «находилсЯ В ОТПУСКЕ. Заявку снимаем…» — живой ответ, а «я в отпуске» нашлось внутри слова.
    private const AUTO_BODY = '/(?<!\p{L})(нахожусь\s+в\s+отпуске|я\s+в\s+отпуске|я\s+отсутству|временно\s+отсутству|доступ\s+к\s+(электронной\s+)?почте\s+ограничен|это\s+автоматическ\w+\s+(ответ|сообщени)|i\s+am\s+out\s+of\s+(the\s+)?office|i\s+am\s+on\s+(annual\s+)?leave|automatic\s+reply)/iu';

    public function __construct(
        private readonly InternalSenderDetector $internal = new InternalSenderDetector,
    ) {}

    /** Причина, по которой письмо не ответ клиента (auto_reply / internal / system / thanks_only), иначе null. */
    public function notAClientReply(EmailMessage $message): ?string
    {
        if ($this->isAutoReply($message)) {
            return 'auto_reply';
        }
        if ($this->isSystemSender((string) $message->from_email)) {
            return 'system';
        }
        if ($this->internal->detect($message) !== null) {
            return 'internal';
        }
        if ($this->isThanksOnly($message)) {
            return 'thanks_only';
        }

        return null;
    }

    public function isAutoReply(EmailMessage $message): bool
    {
        $h = (array) ($message->headers ?? []);
        $submitted = mb_strtolower(trim((string) ($h['auto_submitted'] ?? '')));
        if ($submitted !== '' && $submitted !== 'no') {
            return true;
        }
        if (in_array(mb_strtolower(trim((string) ($h['x_autoreply'] ?? $h['x_autorespond'] ?? ''))), ['yes', 'true', '1'], true)) {
            return true;
        }
        if (preg_match(self::AUTO_SUBJECT, (string) $message->subject) === 1) {
            return true;
        }

        $own = $this->ownText($message);

        return $own !== '' && mb_strlen($own) <= 1500 && preg_match(self::AUTO_BODY, $own) === 1;
    }

    /** Наш служебный отправитель: адрес отправки mzCorp или домен самого приложения (rfq@mzcorp.ru). */
    public function isSystemSender(string $from): bool
    {
        $from = mb_strtolower(trim($from));
        if ($from === '') {
            return false;
        }
        $system = array_filter([mb_strtolower((string) config('mail.from.address'))]);
        $host = mb_strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return in_array($from, $system, true) || ($host !== '' && str_ends_with($from, '@'.$host));
    }

    public function isThanksOnly(EmailMessage $message): bool
    {
        if (str_contains($this->ownText($message), '?') || $this->hasFiles($message)) {
            return false;
        }
        $own = trim($this->ownText($message));
        if ($own === '' || mb_strlen($own) > self::THANKS_MAX_CHARS) {
            return false;
        }

        $thanks = false;
        foreach ($this->phrases($own) as $p) {
            if (preg_match(self::THANKS, $p) === 1) {
                $thanks = true;
            } elseif (preg_match(self::GREETING, $p) !== 1 && ! $this->isName($p, $message)) {
                return false;
            }
        }

        return $thanks;
    }

    /** Свой текст письма: без цитаты и подписи; HTML-письмо без текстовой части — текстом из HTML. */
    private function ownText(EmailMessage $message): string
    {
        $body = (string) $message->body_plain;
        if (trim($body) === '') {
            $body = html_entity_decode(strip_tags((string) preg_replace('/<(br|\/p|\/div)[^>]*>/i', "\n", (string) $message->body_html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $cleaner = app(EmailTextCleanerService::class);

        return trim($cleaner->removeSignature($cleaner->cutQuotedReplyTail($body)));
    }

    /** @return list<string> строки и предложения письма */
    private function phrases(string $text): array
    {
        // По строкам, предложениям и запятым: «Получили, спасибо» — две благодарности, «Спасибо, ждём счёт» — нет.
        $parts = preg_split('/\R+|(?<=[.!])\s+|,\s*/u', $text) ?: [];

        // Края — регуляркой: trim() режет байты, и «—» в его списке обрезало хвост «р» у «эскалатор» (битый UTF-8).
        return array_values(array_filter(array_map(fn ($p) => (string) preg_replace('/^[\s\x{00A0}\-—]+|[\s\x{00A0}\-—]+$/u', '', (string) $p), $parts), fn ($p) => $p !== ''));
    }

    /**
     * Имя в обращении или подписи: «С уважением», «Максим», «Сабиров Максим» — слова из имени отправителя или
     * получателя письма. Просто слово с заглавной не годится: «Спасибо. Согласны» — это ответ.
     */
    private function isName(string $p, EmailMessage $message): bool
    {
        $p = trim((string) preg_replace('/^(с\s+уважением|best\s+regards|kind\s+regards|regards|br)[\s,]*/iu', '', $p), " ,.");
        if ($p === '') {
            return true;
        }
        $names = [(string) $message->from_name];
        foreach ([$message->to_recipients, $message->cc_recipients] as $bag) {
            foreach ((array) $bag as $r) {
                $names[] = is_array($r) ? (string) ($r['name'] ?? '') : '';
            }
        }
        $known = preg_split('/[\s,.\-]+/u', mb_strtolower(implode(' ', $names))) ?: [];
        $known = array_filter($known, fn ($w) => mb_strlen($w) >= 2);
        $words = array_filter(preg_split('/\s+/u', mb_strtolower($p)) ?: [], fn ($w) => $w !== '');

        return $known !== [] && $words !== [] && count($words) <= 3
            && array_diff(array_map(fn ($w) => trim($w, '.'), $words), $known) === [];
    }

    protected function hasFiles(EmailMessage $message): bool
    {
        return $message->attachments()->where(fn ($q) => $q->whereNull('is_inline')->orWhere('is_inline', false))->exists();
    }
}
