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

            return $inquiry;
        });
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
