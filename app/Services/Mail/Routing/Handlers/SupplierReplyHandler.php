<?php

namespace App\Services\Mail\Routing\Handlers;

use App\Enums\EmailCategory;
use App\Services\Mail\Routing\InboundRoutingHandler;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingDecision;
use App\Services\Supplier\SupplierInquiryService;
use Illuminate\Support\Facades\Log;

/**
 * Переписка с поставщиком — три уровня матча, ДО категоризации (экономим LLM)
 * и ДО линкера (ответ поставщика не должен липнуть к клиентской заявке):
 *
 *  1. тред / токен RFQ ([RFQ-<token>] в теме, In-Reply-To/References ↔
 *     thread_root_id / message_id уже прикреплённых писем);
 *  2. пара «отправитель = supplier_email инквайри» + «M-код из темы» — для
 *     запросов с произвольной темой и «Fwd:» без In-Reply-To (M-2026-12940);
 *  3. тема нашего RFQ при разорванном треде (M-2026-9028); если инквайри ещё
 *     не зарегистрирован (гонка отправки/синка) — всё равно НЕ создаём
 *     клиентскую заявку, помечаем supplier_reply.
 *
 * Кейс 0028087@mail.ru: ответы поставщика плодили фантомные заявки.
 */
final class SupplierReplyHandler implements InboundRoutingHandler
{
    public function __construct(private readonly SupplierInquiryService $supplierInquiries)
    {
    }

    public function handle(RoutingContext $ctx): ?RoutingDecision
    {
        $message = $ctx->message;

        try {
            // Детерминированно по токену RFQ ([RFQ-<token>] в теме) — приоритетно
            // над тред-матчем. Ловит ответы на прямые RFQ снабжения (тред часто
            // сломан), прочая переписка поставщика без токена сюда не попадает.
            $supplierInquiry = $this->supplierInquiries->matchInboundByRfqToken($message)
                ?? $this->supplierInquiries->matchInbound($message);
            if ($supplierInquiry !== null) {
                $this->supplierInquiries->attachMessage($supplierInquiry, $message);
                Log::info('MailRouter: supplier inquiry reply — attached, no request', [
                    'email_message_id' => $message->id,
                    'supplier_inquiry_id' => $supplierInquiry->id,
                    'from_email' => $message->from_email,
                ]);

                return new RoutingDecision('supplier_thread', null, ['supplier_inquiry_id' => $supplierInquiry->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('MailRouter: supplier inquiry match failed (non-fatal)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Позитивный матч по паре «отправитель = supplier_email инквайри» +
        // «M-код из темы = в теме инквайри» — БЕЗ гарда на фразу «запрос
        // расценки». Клиент не значится supplier_email инквайри своей же
        // заявки → ложных привязок клиентских писем нет.
        try {
            $bySupplierPair = $this->supplierInquiries->matchInboundBySubject($message);
            if ($bySupplierPair !== null) {
                $this->supplierInquiries->attachMessage($bySupplierPair, $message);
                Log::info('MailRouter: supplier reply matched by sender+code (free-form subject) — attached, no request', [
                    'email_message_id' => $message->id,
                    'supplier_inquiry_id' => $bySupplierPair->id,
                    'from_email' => $message->from_email,
                ]);

                return new RoutingDecision('supplier_sender_code', null, ['supplier_inquiry_id' => $bySupplierPair->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('MailRouter: supplier sender+code match failed (non-fatal)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback по ТЕМЕ: ответ на НАШ RFQ, но тред разорван. Ответ на наш RFQ
        // клиентской заявкой быть НЕ может — гасим до linker'а.
        if ($this->supplierInquiries->looksLikeRfqReply($message)) {
            try {
                $bySubject = $this->supplierInquiries->matchInboundBySubject($message);
                if ($bySubject !== null) {
                    $this->supplierInquiries->attachMessage($bySubject, $message);
                    Log::info('MailRouter: supplier reply matched by RFQ subject (broken thread) — attached, no request', [
                        'email_message_id' => $message->id,
                        'supplier_inquiry_id' => $bySubject->id,
                        'from_email' => $message->from_email,
                    ]);

                    return new RoutingDecision('supplier_rfq_subject', null, ['supplier_inquiry_id' => $bySubject->id]);
                }
                // Инквайри ещё не зарегистрирован (гонка отправки/синка) — всё
                // равно НЕ создаём клиентскую заявку. Помечаем как переписку
                // поставщика; привяжется, когда инквайри появится.
                $message->forceFill([
                    'category' => EmailCategory::SupplierReply->value,
                    'categorized_at' => now(),
                ])->save();
                Log::info('MailRouter: RFQ-subject reply, inquiry not registered yet — client request suppressed', [
                    'email_message_id' => $message->id,
                    'from_email' => $message->from_email,
                    'subject' => $message->subject,
                ]);

                return new RoutingDecision('supplier_rfq_unregistered');
            } catch (\Throwable $e) {
                Log::warning('MailRouter: RFQ-subject supplier fallback failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }
}
