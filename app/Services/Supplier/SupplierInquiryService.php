<?php

namespace App\Services\Supplier;

use App\Enums\EmailCategory;
use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Models\Request as RequestModel;
use App\Models\SupplierInquiry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Модуль поставщиков (фундамент): пометка пойманного треда как нашего запроса
 * расценки поставщику + матчинг последующих ответов в этом треде.
 *
 * Тред-центрично и КОНСЕРВАТИВНО: ответ матчится к запросу поставщику ТОЛЬКО
 * по цепочке треда (In-Reply-To / References ↔ thread_root_id или message_id
 * уже прикреплённых писем). Никакого авто-подавления по контакту — срабатывает
 * лишь на явно помеченных тредах (по решению заказчика). См.
 * [[clients-section]] (тот же контрагент бывает и клиентом, и поставщиком).
 */
class SupplierInquiryService
{
    public function __construct(
        private readonly SupplierRegistry $registry,
    ) {
    }

    /**
     * Зарегистрировать запрос поставщику из НАШЕГО ИСХОДЯЩЕГО письма (send-time).
     * Триггерится из MailRouter, когда получатель в реестре поставщиков И LLM
     * подтвердил RFQ. thread_root_id = message_id исходящего — ответ поставщика
     * (In-Reply-To на него) поймает matchInbound. Идемпотентно по thread_root_id.
     * Заодно регистрирует поставщика в реестре (bootstrap). null — нет финального
     * Message-ID (рано) или нет получателя.
     */
    public function createFromOutbound(EmailMessage $sent, ?int $requestId, ?User $by): ?SupplierInquiry
    {
        $rootId = (string) $sent->message_id;
        if ($rootId === '' || str_starts_with($rootId, 'draft.')) {
            return null;
        }

        $recipients = (array) ($sent->to_recipients ?? []);
        $first = is_array($recipients[0] ?? null) ? $recipients[0] : [];
        $supplierEmail = mb_strtolower(trim((string) ($first['email'] ?? '')));
        $supplierName = isset($first['name']) ? (string) $first['name'] : null;
        if ($supplierEmail === '') {
            return null;
        }

        return DB::transaction(function () use ($sent, $requestId, $by, $rootId, $supplierEmail, $supplierName) {
            $inquiry = SupplierInquiry::query()->where('thread_root_id', $rootId)->first();
            if ($inquiry === null) {
                // Дедуп на исходящем пути: письмо могло уйти НОВЫМ тредом
                // (менеджер ответил поставщику из личной почты → свой message_id,
                // ссылка не на наш RFQ). Не плодим второй инквайри, если по коду
                // темы уже есть открытый ТОМУ ЖЕ поставщику по ТОЙ ЖЕ заявке.
                // Гарды sameParty + совпадение заявки защищают от слияния разных
                // поставщиков, делящих общий M-код (кейс M-2026-11035 / #2645).
                $byCode = $this->matchByCodeInSubject((string) $sent->subject, $supplierEmail);
                if ($byCode !== null
                    && ($requestId === null || $byCode->related_request_id === null
                        || (int) $byCode->related_request_id === (int) $requestId)
                    && $this->sameParty((string) $byCode->supplier_email, $supplierEmail)) {
                    $inquiry = $byCode;
                }
            }
            if ($inquiry === null) {
                $inquiry = SupplierInquiry::create([
                    'supplier_email' => $supplierEmail,
                    'supplier_name' => $supplierName !== null && $supplierName !== '' ? $supplierName : null,
                    'subject' => $sent->subject,
                    'thread_root_id' => $rootId,
                    'related_request_id' => $requestId,
                    'status' => 'open',
                    'created_by_user_id' => $by?->id,
                    // Токен RFQ из темы ([RFQ-<token>]) — для детерминированного
                    // матча ответа поставщика (SupplierDispatchService его ставит).
                    'rfq_token' => $this->extractRfqToken($sent->subject),
                ]);
            }
            $this->attachMessage($inquiry, $sent);
            $this->registry->registerEmail($supplierEmail, $supplierName, $by);

            return $inquiry;
        });
    }

    /** Тот же контрагент: точный e-mail или общий домен. */
    private function sameParty(string $a, string $b): bool
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
     * Пометить тред заявки как наш запрос поставщику: создать/найти
     * SupplierInquiry по корню треда + прицепить все письма заявки как
     * переписку с поставщиком (category=supplier_reply, supplier_inquiry_id).
     * related_request_id НЕ ставим — заявка является фантомом и закрывается;
     * реальную клиентскую заявку (если есть) оператор свяжет вручную.
     */
    public function markFromRequest(RequestModel $request, ?User $by): SupplierInquiry
    {
        $origin = $request->emailMessage;
        $supplierEmail = mb_strtolower(trim((string) ($origin?->from_email ?: $request->client_email)));
        $supplierName = $origin?->from_name ?: $request->client_name;
        $subject = $origin?->subject ?: $request->subject;
        $threadRoot = $this->threadRoot($origin);

        return DB::transaction(function () use ($request, $by, $origin, $supplierEmail, $supplierName, $subject, $threadRoot) {
            $inquiry = null;
            if ($threadRoot !== null) {
                $inquiry = SupplierInquiry::where('thread_root_id', $threadRoot)->first();
            }
            if ($inquiry === null && $origin !== null) {
                $inquiry = $this->matchInbound($origin);
            }
            if ($inquiry === null) {
                $inquiry = SupplierInquiry::create([
                    'supplier_email' => $supplierEmail,
                    'supplier_name' => $supplierName !== '' ? $supplierName : null,
                    'subject' => $subject !== '' ? $subject : null,
                    'thread_root_id' => $threadRoot ?: ($origin?->message_id),
                    'status' => 'open',
                    'created_by_user_id' => $by?->id,
                ]);
            }

            // Прицепить все письма этой заявки + само originating-письмо.
            EmailMessage::query()
                ->where('related_request_id', $request->id)
                ->get()
                ->each(fn (EmailMessage $m) => $this->attachMessage($inquiry, $m));
            if ($origin !== null) {
                $this->attachMessage($inquiry, $origin);
            }

            // Bootstrap реестра: помеченный вручную поставщик попадает в список,
            // чтобы его будущие исходящие RFQ ловились send-time автоматически.
            if ($supplierEmail !== '') {
                $this->registry->registerEmail($supplierEmail, $supplierName, $by);
            }

            return $inquiry;
        });
    }

    /**
     * Принять входящее письмо от поставщика как переписку (для supplier-kind
     * стоп-листа: весь ящик — поставщик). Сначала тред-матч (matchInbound),
     * иначе общий inquiry по supplier_email (создаём, если нет). Делает письмо
     * ЧИТАЕМЫМ в /dashboard/suppliers, не создавая клиентской заявки.
     */
    public function ingestSupplierMessage(EmailMessage $message): SupplierInquiry
    {
        // 1) строгий тред-матч; 2) по номеру заявки/док-номеру из ТЕМЫ (поставщик
        //    часто рвёт In-Reply-To, но сохраняет тему с нашим кодом) — иначе
        //    ответ по одной позиции валился в «последний инквайри по e-mail» и
        //    портил другой запрос (кейс es-escalatorpart: цена на M-2026-11154
        //    села в инквайри по M-2026-11295). 3) фолбэк по e-mail.
        // 0) ДЕТЕРМИНИРОВАННО по токену RFQ в теме ([RFQ-<token>]) — самый
        //    надёжный: токен уникален на инквайри, поставщик сохраняет тему.
        $inquiry = $this->matchInboundByRfqToken($message)
            ?? $this->matchInbound($message)
            ?? $this->matchInboundByAnyCode($message);
        if ($inquiry === null) {
            $email = mb_strtolower(trim((string) $message->from_email));
            $inquiry = SupplierInquiry::query()
                ->whereRaw('lower(supplier_email) = ?', [$email])
                ->orderByDesc('id')
                ->first();
            if ($inquiry === null) {
                $inquiry = SupplierInquiry::create([
                    'supplier_email' => $email,
                    'supplier_name' => $message->from_name ?: null,
                    'subject' => $message->subject ?: 'Переписка с поставщиком',
                    'thread_root_id' => $message->message_id ?: null,
                    'status' => 'open',
                ]);
            }
        }
        $this->attachMessage($inquiry, $message);

        return $inquiry;
    }

    /**
     * Найти запрос поставщику, к которому относится входящее письмо, СТРОГО по
     * цепочке треда. null — не относится ни к одному помеченному треду.
     */
    public function matchInbound(EmailMessage $message): ?SupplierInquiry
    {
        if ($message->direction !== MailDirection::Inbound) {
            return null;
        }

        $refs = $this->threadRefs($message);
        if ($refs === []) {
            return null;
        }

        // 1) Корень треда совпал с помеченным запросом поставщику.
        $byRoot = SupplierInquiry::query()->whereIn('thread_root_id', $refs)->first();
        if ($byRoot !== null) {
            return $byRoot;
        }

        // 2) Письмо ссылается на сообщение, уже прикреплённое к запросу
        //    поставщику (цепочка треда продолжается).
        $viaMsg = EmailMessage::query()
            ->whereNotNull('supplier_inquiry_id')
            ->whereIn('message_id', $refs)
            ->orderByDesc('id')
            ->first();
        if ($viaMsg !== null) {
            return SupplierInquiry::find($viaMsg->supplier_inquiry_id);
        }

        return null;
    }

    /**
     * Похоже ли письмо на ответ на НАШ запрос расценки — по ТЕМЕ.
     *
     * Нужно, когда тред разорван на уровне заголовков: поставщик пересылает наш
     * RFQ внутри себя («Re: Fw: …»), In-Reply-To пуст, а References указывают на
     * его собственный домен, а не на наш message_id — matchInbound по цепочке не
     * срабатывает. Единственная ниточка к RFQ остаётся в теме.
     *
     * Гард строгий: тема должна содержать И префикс нашего RFQ
     * («Price request» / «Запрос расценки»), И наш внутренний код заявки
     * (M-YYYY-NNNN). Клиентское письмо так выглядеть не может — код заявки
     * знаем только мы, и он попадает наружу лишь в исходящем RFQ поставщику.
     */
    public function looksLikeRfqReply(EmailMessage $message): bool
    {
        if ($message->direction !== MailDirection::Inbound) {
            return false;
        }
        $subject = (string) $message->subject;

        return preg_match('/(price request|запрос расценки)/iu', $subject) === 1
            && preg_match('/\bM-\d{4}-\d+/u', $subject) === 1;
    }

    /**
     * Найти запрос поставщику по ТЕМЕ ответа (fallback к matchInbound, когда
     * тред разорван). Матч по паре «почта поставщика = отправитель» + «код
     * заявки из темы присутствует в теме инквайри». Возвращает последний
     * подходящий инквайри или null (в т.ч. если инквайри ещё не зарегистрирован).
     */
    public function matchInboundBySubject(EmailMessage $message): ?SupplierInquiry
    {
        if (! $this->looksLikeRfqReply($message)) {
            return null;
        }
        if (! preg_match('/\bM-\d{4}-\d+/u', (string) $message->subject, $m)) {
            return null;
        }
        $code = $m[0];
        $from = mb_strtolower(trim((string) $message->from_email));
        if ($from === '') {
            return null;
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $code) . '%';

        return SupplierInquiry::query()
            ->whereRaw('LOWER(supplier_email) = ?', [$from])
            ->where('subject', 'ilike', $like)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Найти запрос поставщику по ЛЮБОМУ коду в теме ответа: наш внутренний код
     * заявки (M-YYYY-NNNN) ИЛИ док-номер (6–9 цифр, напр. 363530 / 000363395).
     * Поставщик рвёт In-Reply-To, но сохраняет тему; код в теме — надёжная
     * ниточка к правильному инквайри. Без гарда на фразу «price request»: этот
     * матч зовём там, где отправитель УЖЕ известен как поставщик (blocklist).
     * Матч строго по паре «почта поставщика = отправитель» + «код есть в теме
     * инквайри». M-код приоритетнее док-номера; среди совпадений — открытый и
     * последний.
     */
    /** Regex токена RFQ в теме: `[RFQ-A3F9K2]`. */
    private const RFQ_TOKEN_RE = '/\[RFQ-([A-Z0-9]{4,12})\]/i';

    /**
     * Уникальный токен RFQ для нового инквайри. Ставится маркером в тему,
     * поставщик сохраняет тему при ответе → детерминированный матч ответа.
     */
    public function generateRfqToken(): string
    {
        do {
            $token = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(7));
        } while (SupplierInquiry::query()->where('rfq_token', $token)->exists());

        return $token;
    }

    /** Маркер токена для добавления в тему письма-запроса. */
    public function rfqMarker(string $token): string
    {
        return '[RFQ-' . mb_strtoupper(trim($token)) . ']';
    }

    /** Извлечь токен RFQ из темы (для createFromOutbound и матча ответа). */
    public function extractRfqToken(?string $subject): ?string
    {
        if (preg_match(self::RFQ_TOKEN_RE, (string) $subject, $m) === 1) {
            return mb_strtoupper($m[1]);
        }

        return null;
    }

    /**
     * ДЕТЕРМИНИРОВАННЫЙ матч ответа поставщика по токену RFQ в теме. Самый
     * надёжный путь: токен уникален на инквайри (заявка/пул × поставщик),
     * поставщик сохраняет тему. Только inbound. Прочая переписка снабжения
     * (закупки/отгрузки/рекламации) токена не несёт → не матчится, игнорируется.
     */
    public function matchInboundByRfqToken(EmailMessage $message): ?SupplierInquiry
    {
        if ($message->direction !== MailDirection::Inbound) {
            return null;
        }
        $token = $this->extractRfqToken($message->subject);
        if ($token === null) {
            return null;
        }

        return SupplierInquiry::query()->where('rfq_token', $token)->first();
    }

    public function matchInboundByAnyCode(EmailMessage $message): ?SupplierInquiry
    {
        if ($message->direction !== MailDirection::Inbound) {
            return null;
        }

        return $this->matchByCodeInSubject((string) $message->subject, (string) $message->from_email);
    }

    /**
     * Общий матч инквайри по коду в теме (M-код заявки / номер RFQ в скобках)
     * + адрес контрагента. Единая логика для входящих (matchInboundByAnyCode)
     * и для отправки (createFromOutbound) — чтобы дубли не плодились ни на
     * входящем, ни на исходящем пути. $counterpartEmail: для входящих это
     * from_email, для исходящих — e-mail получателя (поставщика). Пустой адрес
     * → только фоллбэк по «сильному» коду (уникальный номер RFQ / M-код).
     */
    public function matchByCodeInSubject(string $subject, string $counterpartEmail = ''): ?SupplierInquiry
    {
        $subject = trim($subject);
        if ($subject === '') {
            return null;
        }
        $from = mb_strtolower(trim($counterpartEmail));

        $codes = [];
        // M-код заявки (приоритетнее — уникальнее), затем док-номера 6–9 цифр
        // (наши RFQ несут [363530] / 000363395).
        preg_match_all('/\bM-\d{4}-\d+/u', $subject, $m);
        foreach ($m[0] ?? [] as $c) {
            $codes[] = $c;
        }
        preg_match_all('/\b\d{6,9}\b/u', $subject, $m2);
        foreach ($m2[0] ?? [] as $c) {
            $codes[] = $c;
        }
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return null;
        }

        // Домен отправителя: RFQ часто уходит на один контакт поставщика
        // (sales@/amy@), а отвечает другой (ella@) того же домена. Точный e-mail
        // не совпадёт — матчим и по домену. Домен = один поставщик, а код
        // разводит внутри (один M-код мог уйти нескольким поставщикам — их
        // домены разные).
        if ($from !== '') {
            $domain = mb_strtolower(trim((string) substr((string) strrchr($from, '@'), 1)));

            foreach ($codes as $code) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $code).'%';
                $base = fn () => SupplierInquiry::query()
                    ->where('subject', 'ilike', $like)
                    ->orderByRaw("case when status = 'open' then 0 else 1 end")
                    ->orderByDesc('id');

                $hit = (clone $base())->whereRaw('LOWER(supplier_email) = ?', [$from])->first();
                if ($hit !== null) {
                    return $hit;
                }
                if ($domain !== '') {
                    $hit = (clone $base())->whereRaw("split_part(lower(supplier_email), '@', 2) = ?", [$domain])->first();
                    if ($hit !== null) {
                        return $hit;
                    }
                }
            }
        }

        // Фоллбэк по коду БЕЗ совпадения адреса/домена: ответ мог прийти через
        // постороннего посредника (наш RFQ переслали, отвечает третий адрес
        // чужого домена — кейс fam-drive через inbox.ru). Наш код в теме
        // уникален, поэтому привязываем по нему — но ТОЛЬКО «сильные» коды:
        // M-код заявки и НАШ номер RFQ в скобках [363328]. Голые 6–9-значные
        // числа сюда НЕ берём (part-номер вроде 714280102 дал бы ложный матч).
        // Привязываем лишь если код указывает на РОВНО ОДИН инквайри (открытый —
        // приоритетно); неоднозначность (несколько поставщиков на одну заявку по
        // M-коду) → пропускаем, там нужен адрес/домен.
        $strong = [];
        preg_match_all('/\bM-\d{4}-\d+/u', $subject, $ms);
        foreach ($ms[0] ?? [] as $c) {
            $strong[] = $c;
        }
        preg_match_all('/\[(\d{6,9})\]/u', $subject, $mb);
        foreach ($mb[1] ?? [] as $c) {
            $strong[] = $c;
        }
        foreach (array_values(array_unique($strong)) as $code) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $code).'%';
            $cands = SupplierInquiry::query()
                ->where('subject', 'ilike', $like)
                ->orderByRaw("case when status = 'open' then 0 else 1 end")
                ->orderByDesc('id')
                ->get();
            $open = $cands->where('status', 'open');
            if ($open->count() === 1) {
                return $open->first();
            }
            if ($cands->count() === 1) {
                return $cands->first();
            }
        }

        return null;
    }

    /**
     * Прицепить письмо к запросу поставщику: проставить supplier_inquiry_id +
     * для входящих category=supplier_reply (чтобы не уходило в client-pipeline
     * и mail-review). Идемпотентно.
     */
    public function attachMessage(SupplierInquiry $inquiry, EmailMessage $message): void
    {
        $fill = [];
        if ($message->supplier_inquiry_id !== $inquiry->id) {
            $fill['supplier_inquiry_id'] = $inquiry->id;
        }
        if ($message->direction === MailDirection::Inbound
            && $message->category !== EmailCategory::SupplierReply->value) {
            $fill['category'] = EmailCategory::SupplierReply->value;
            $fill['category_reasoning'] = 'Переписка с поставщиком (запрос #'.$inquiry->id.')';
            // categorized_at — чтобы крон mail:categorize не переразбирал письмо
            // и оно не утекло в /mail-review (фильтр category=irrelevant).
            if ($message->categorized_at === null) {
                $fill['categorized_at'] = now();
            }
        }
        if ($fill !== []) {
            $message->forceFill($fill)->save();
        }

        // Фаза 3.3: входящий ответ поставщика на запрос С ПОЗИЦИЯМИ — разобрать
        // в предложения (async, идемпотентно по message_id).
        if ($message->direction === MailDirection::Inbound && $inquiry->items()->exists()) {
            \App\Jobs\Suppliers\ParseSupplierReplyJob::dispatch($message->id, $inquiry->id);
        }
    }

    /** Является ли email поставщиком (есть хотя бы один помеченный запрос). */
    public function isKnownSupplier(string $email): bool
    {
        $email = mb_strtolower(trim($email));

        return $email !== '' && SupplierInquiry::query()
            ->whereRaw('lower(supplier_email) = ?', [$email])
            ->exists();
    }

    /**
     * Идентификаторы треда письма (In-Reply-To + References), без пустых.
     *
     * @return array<int, string>
     */
    private function threadRefs(EmailMessage $message): array
    {
        $refs = [];
        if (! empty($message->in_reply_to)) {
            $refs[] = (string) $message->in_reply_to;
        }
        foreach ((array) ($message->references_header ?? []) as $r) {
            if (is_string($r) && trim($r) !== '') {
                $refs[] = $r;
            }
        }

        return array_values(array_unique(array_filter($refs)));
    }

    /** Корень цепочки треда: самый ранний References, иначе In-Reply-To. */
    private function threadRoot(?EmailMessage $message): ?string
    {
        if ($message === null) {
            return null;
        }
        foreach ((array) ($message->references_header ?? []) as $r) {
            if (is_string($r) && trim($r) !== '') {
                return $r;
            }
        }

        return $message->in_reply_to ?: null;
    }
}
