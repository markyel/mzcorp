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
                $inquiry = SupplierInquiry::create([
                    'supplier_email' => $supplierEmail,
                    'supplier_name' => $supplierName !== null && $supplierName !== '' ? $supplierName : null,
                    'subject' => $sent->subject,
                    'thread_root_id' => $rootId,
                    'related_request_id' => $requestId,
                    'status' => 'open',
                    'created_by_user_id' => $by?->id,
                ]);
            }
            $this->attachMessage($inquiry, $sent);
            $this->registry->registerEmail($supplierEmail, $supplierName, $by);

            return $inquiry;
        });
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
        $inquiry = $this->matchInbound($message);
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
