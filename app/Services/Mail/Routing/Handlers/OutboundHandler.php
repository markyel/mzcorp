<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Enums\MailDirection;
use App\Models\EmailMessage;
use App\Services\Mail\CrossMailboxCopyMatcher;
use App\Services\Mail\OutboundDocumentDetectionService;
use App\Services\Mail\OutgoingMailLinker;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use App\Services\Supplier\SupplierInquiryService;
use App\Services\Supplier\SupplierRegistry;
use App\Services\Supplier\SupplierRfqClassifier;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1.9 outbound: исходящие из Sent — линкуем к существующей Request, не
 * пропускаем через categorize/rules/IncomingProcessor (это наше письмо, не
 * клиентский запрос). Отдельная короткая ветка:
 *   1. OutgoingMailLinker;
 *   2. обратный cross-mailbox дедуп (ретроактивно подшить копии в чужих ящиках);
 *   3. RFQ поставщику (LLM) — ДО документ-детектора;
 *   4. детектор исходящих документов (КП/счёт/уточнение/отказ).
 */
final class OutboundHandler implements InboundRoutingHandler
{
    public function __construct(
        private readonly OutgoingMailLinker $outgoingLinker,
        private readonly SupplierRegistry $supplierRegistry,
        private readonly SupplierRfqClassifier $supplierRfqClassifier,
        private readonly SupplierInquiryService $supplierInquiries,
        private readonly OutboundDocumentDetectionService $documentDetection,
    ) {
    }

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;
        if ($message->direction !== MailDirection::Outbound) {
            return null;
        }

        try {
            $linkedRequest = $this->outgoingLinker->tryLink($message);
        } catch (\Throwable $e) {
            $linkedRequest = null;
            Log::warning('MailRouter: outgoing linker failed (non-fatal)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Обратный cross-mailbox дедуп. Наше исходящее, попавшее в копию
        // (CC) во внутренний ящик коллеги, синкается как ОТДЕЛЬНОЕ inbound
        // с тем же message_id. Если эта копия пришла РАНЬШЕ, чем outbound
        // получил related_request_id, прямой дедуп в route() её пропустил —
        // она осела rel=null + category=irrelevant. Потом на неё ложно
        // срабатывал orphan-defer линкера и плодил пустую заявку (кейс
        // M-2026-4688: «выгрузили доки?» в треде закрытой 3965). Теперь,
        // когда исходящее залинковано, ретроактивно подшиваем такие копии
        // к той же заявке + помечаем cross_mailbox_copy_of (UI-тред их
        // прячет, линкер больше не считает их orphan'ами).
        if ($linkedRequest !== null && $message->message_id) {
            $this->backfillCrossMailboxCopies($message, $linkedRequest->id);
        }

        // Запрос расценки поставщику (модуль поставщиков, send-time): если
        // наше исходящее ушло получателю из реестра поставщиков И LLM
        // подтвердил, что это RFQ (мы просим цены на номенклатуру, а не
        // отвечаем контрагенту как клиенту) — регистрируем тред
        // (thread_root_id = message_id). Ответ поставщика (In-Reply-To на
        // него) поймает matchInbound и не создаст фейковую заявку. Ловим и
        // письма из почтового клиента (синкаются из Sent). Идемпотентно.
        //
        // ВАЖНО: детектим RFQ ДО outbound document detector. RFQ поставщику
        // обычно несёт в теме код клиентской заявки ([M-2026-9298]) для
        // трассировки, поэтому outgoingLinker привязывает его к клиентской
        // заявке. Если прогнать такой RFQ через клиентский документ-детектор,
        // он классифицируется как КП/уточнение КЛИЕНТУ и ломает статус
        // заявки (кейс M-2026-9298: RFQ → outbound_clarification → «Жду
        // клиента»). createFromOutbound ставит письму supplier_inquiry_id,
        // и клиентская вкладка «Переписка» его прячет (Detail::thread).
        $isSupplierRfq = false;
        try {
            if ($message->message_id) {
                $toSupplier = collect((array) ($message->to_recipients ?? []))
                    ->map(fn ($r) => is_array($r) ? ($r['email'] ?? null) : $r)
                    ->filter()
                    ->first(fn ($e) => $this->supplierRegistry->isSupplier((string) $e));
                // Гард: получатель — КЛИЕНТ привязанной заявки → это ответ
                // клиенту, а НЕ запрос поставщику, даже если его e-mail по
                // ошибке попал в реестр suppliers (клиент может быть занесён
                // в поставщики вручную при закрытии заявки, или домен-матч).
                // Кейс M-2026-10891: Джалал Абасов — крупный клиент (433
                // заявки), ошибочно в suppliers → ответ ему стал «RFQ».
                $recipientIsClient = $toSupplier !== null
                    && $linkedRequest !== null
                    && filled($linkedRequest->client_email)
                    && mb_strtolower(trim((string) $toSupplier)) === mb_strtolower(trim((string) $linkedRequest->client_email));
                if ($toSupplier !== null && ! $recipientIsClient) {
                    $rfq = $this->supplierRfqClassifier->classify($message);
                    if ($rfq['is_rfq']) {
                        $inquiry = $this->supplierInquiries->createFromOutbound(
                            $message,
                            $message->related_request_id,
                            null,
                        );
                        $isSupplierRfq = true;
                        Log::info('MailRouter: outbound supplier RFQ — inquiry registered', [
                            'email_message_id' => $message->id,
                            'supplier_inquiry_id' => $inquiry?->id,
                            'confidence' => $rfq['confidence'],
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MailRouter: supplier RFQ detect failed (non-fatal)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Phase 4 (Foundation §7.1): outbound document detector.
        // Сработает только если linker уже привязал письмо к Request —
        // иначе непонятно к чему относится «КП» (общая переписка с
        // клиентом по другим заявкам, маркетинг и т.п.). НЕ запускаем для
        // RFQ поставщику — это не клиентский документ (см. выше).
        if ($linkedRequest !== null && ! $isSupplierRfq) {
            $this->documentDetection->run($message, $linkedRequest);
        }

        return new RoutingDecision('outbound', $linkedRequest?->id, ['supplier_rfq' => $isSupplierRfq]);
    }

    private function backfillCrossMailboxCopies(EmailMessage $message, int $requestId): void
    {
        $copies = EmailMessage::query()
            ->where('message_id', $message->message_id)
            ->where('id', '!=', $message->id)
            ->whereNull('related_request_id')
            ->get();

        foreach ($copies as $copy) {
            // Тот же Message-ID, но другой subject/Date — отдельное письмо
            // (Outlook Thread-Index reuse), а не копия. Не подшиваем.
            if (! CrossMailboxCopyMatcher::isSamePhysicalMessage($message, $copy)) {
                continue;
            }
            $artifacts = (array) ($copy->detected_artifacts ?? []);
            $artifacts['cross_mailbox_copy_of'] = $message->id;
            $copy->forceFill([
                'related_request_id' => $requestId,
                'detected_artifacts' => $artifacts,
            ])->save();

            Log::info('MailRouter: backfilled prior cross-mailbox copy to request', [
                'copy_email_message_id' => $copy->id,
                'source_email_message_id' => $message->id,
                'request_id' => $requestId,
                'message_id' => $message->message_id,
            ]);
        }
    }
}
