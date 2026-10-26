<?php

namespace App\Services\Mail;

use App\Enums\DetectorType;
use App\Jobs\Quotes\ParseOutboundQuoteJob;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Services\DocumentDetector\AiDecisionService;
use App\Services\DocumentDetector\OutboundDocumentClassifier;
use App\Services\DocumentDetector\OutboundDocumentDetector;
use Illuminate\Support\Facades\Log;

/**
 * Детектор исходящих документов (Phase 4, Foundation §7.1): rule-based
 * (быстрое, ловит явные КП/счёт по filename/keyword) → LLM fallback → запись
 * AiDecision (auto-apply переводит заявку в Quoted/Invoiced/… при confidence
 * ≥ порога) → парсер вложений (OutboundQuote/Invoice). Идемпотентно:
 * recordSuggestion дедупит по (email_message_id, type), ParseOutboundQuoteJob
 * — по attachment.
 *
 * Вызывается из цепочки маршрутизации (OutboundHandler), из
 * OutboundReplyHooks после отправки из композера и из команды
 * quotes:detect-missed-outbound (добивает письма, привязанные к заявке ПОСЛЕ
 * синка — гонка «КП раньше заявки»). Сбои не фатальны. Логика перенесена из
 * MailRouter без изменений (2026-09-09).
 */
class OutboundDocumentDetectionService
{
    public function __construct(
        private readonly OutboundDocumentDetector $outboundDetector,
        private readonly OutboundDocumentClassifier $outboundLlmClassifier,
        private readonly AiDecisionService $aiDecisions,
        private readonly InternalSenderDetector $internalDetector = new InternalSenderDetector(),
    ) {
    }

    /** Отказ по заявке из нескольких позиций — ниже порога автоприменения: решает менеджер по плашке. */
    public const MULTI_ITEM_DECLINE_CONFIDENCE = 0.8;

    /** Отказ с продолжением — уточнение у клиента, как у rule-based (не авто, плашка). */
    public const FOLLOWUP_CLARIFICATION_CONFIDENCE = 0.65;

    /**
     * «Отказ» (правила или LLM) — проверка, прежде чем закрыть заявку потерей:
     *  - в своём тексте менеджера есть продолжение (вопрос, альтернатива, «если устроит…») — это
     *    уточнение у клиента, а не отказ (M-2026-20979: «не предложим в сборе, готовы по-элементно?»);
     *  - в заявке несколько позиций — отказ обычно по одной («башмаки не поставим», 3 позиции):
     *    уверенность ниже порога, автозакрытия нет, менеджер подтверждает плашкой.
     * За 60 дней до 26.10.2026 из 142 автозакрытий «отказом» 15 менеджеры откатили вручную.
     *
     * @return array{type: DetectorType, confidence: float, guard: ?string}
     */
    public static function guardDecline(float $confidence, ?string $followup, int $activeItems): array
    {
        if ($followup !== null) {
            return ['type' => DetectorType::OutboundClarification, 'confidence' => min($confidence, self::FOLLOWUP_CLARIFICATION_CONFIDENCE), 'guard' => 'followup: '.$followup];
        }
        if ($activeItems > 1) {
            return ['type' => DetectorType::OutboundDeclined, 'confidence' => min($confidence, self::MULTI_ITEM_DECLINE_CONFIDENCE), 'guard' => 'positions: '.$activeItems];
        }

        return ['type' => DetectorType::OutboundDeclined, 'confidence' => $confidence, 'guard' => null];
    }

    public function run(EmailMessage $message, Request $request): void
    {
        try {
            // Гард по получателю: КП/счёт/уточнение/отказ — документы КЛИЕНТУ.
            // Внутренний пересыл коллеге или письмо третьей стороне (даже если
            // оно прилинковано к заявке по In-Reply-To) статус не двигает.
            // Кейс M-2026-14608: «Fwd: Запрос счета…» из info@ на сторонний
            // адрес с PDF «Реквизиты ООО …» → LLM решил «счёт» → Invoiced
            // без счёта. См. InternalSenderDetector::isAddressedToClient.
            if (! $this->internalDetector->isAddressedToClient($message, $request->client_email, $request)) {
                Log::info('MailRouter: outbound document detector skipped — not addressed to client', [
                    'email_message_id' => $message->id,
                    'request_id' => $request->id,
                    'client_email' => $request->client_email,
                    'to' => $message->to_recipients,
                    'cc' => $message->cc_recipients,
                ]);

                return;
            }

            // Two-tier: rule-based (быстрое, ловит явные КП/счёт по
            // filename/keyword) → LLM fallback (gpt-4o-mini, добивает
            // edge-cases типа «Предложение МЗ-355319.pdf» / body=«КП»
            // / HTML-only через portal). LLM дёргается, если rule-based
            // null или ниже auto-apply threshold (decision иначе застрянет
            // в suggested); при равной/меньшей уверенности — остаёмся на rule.
            $detected = $this->outboundDetector->analyze($message->fresh(), $request);
            $autoApplyThreshold = (float) app_setting('detector.confidence_threshold', 0.85);
            if ($detected === null || $detected['confidence'] < $autoApplyThreshold) {
                $llm = $this->outboundLlmClassifier->classify($message->fresh(), $request);
                if ($llm !== null
                    && ($detected === null || $llm['confidence'] > $detected['confidence'])
                ) {
                    $detected = $llm;
                }
            }
            if ($detected !== null && $detected['type'] === DetectorType::OutboundDeclined) {
                $guarded = self::guardDecline((float) $detected['confidence'], $this->outboundDetector->declineFollowup($message),
                    $request->items()->where('is_active', true)->count());
                if ($guarded['guard'] !== null) {
                    Log::info('MailRouter: outbound decline guarded', [
                        'email_message_id' => $message->id, 'request_id' => $request->id,
                        'guard' => $guarded['guard'], 'type' => $guarded['type']->value, 'confidence' => $guarded['confidence'],
                    ]);
                    $detected['type'] = $guarded['type'];
                    $detected['confidence'] = $guarded['confidence'];
                    $detected['signals']['decline_guard'] = $guarded['guard'];
                    if ($guarded['type'] !== DetectorType::OutboundDeclined) {
                        unset($detected['suggested_closed_lost_reason'], $detected['cited_phrase']);
                    }
                }
            }
            if ($detected !== null) {
                // Для outbound_declined пробрасываем suggested_closed_lost_reason
                // и cited_phrase в payload — AiDecisionService::apply прочитает
                // их при ClosedLost-переходе (reason + цитата).
                $suggestionPayload = ['signals' => $detected['signals']];
                if (isset($detected['suggested_closed_lost_reason'])) {
                    $suggestionPayload['suggested_closed_lost_reason'] = $detected['suggested_closed_lost_reason'];
                }
                if (isset($detected['cited_phrase']) && $detected['cited_phrase'] !== null) {
                    $suggestionPayload['cited_phrase'] = $detected['cited_phrase'];
                }
                $this->aiDecisions->recordSuggestion(
                    $detected['type'],
                    $request,
                    $message,
                    (float) $detected['confidence'],
                    $suggestionPayload,
                );

                // Парсер исходящего КП/счёта — distill позиций+цен из PDF/XLSX/DOCX
                // вложений. Dispatch async-job на каждое подходящее вложение;
                // ShouldBeUnique по attachment_id гасит дубли.
                $this->dispatchOutboundQuoteParsing($message, $detected['type']);
            }
        } catch (\Throwable $e) {
            Log::warning('MailRouter: outbound document detector failed (non-fatal)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Триггер парсера исходящих КП/счетов (Foundation §7, расширение).
     *
     * Отбор: тип события — quotation/invoice (clarification и прочие игнорируем),
     * расширение вложения — из `services.quotes.parseable_extensions`. Размер +
     * наличие файла проверяет уже сам job (Guard'ы в handle()).
     *
     * Идемпотентность гарантируется `ParseOutboundQuoteJob::uniqueId()` по
     * attachment_id — повторный запуск через MailRouter (sync пересортировка)
     * не плодит дубли.
     */
    private function dispatchOutboundQuoteParsing(EmailMessage $message, DetectorType $letterType): void
    {
        // Парсим только КП и счёт. Clarification и outbound_quotation_partial
        // (partial — пока зарезервирован, не используется детектором) — пропускаем.
        if (! in_array($letterType, [DetectorType::OutboundQuotationFull, DetectorType::OutboundInvoice], true)) {
            return;
        }

        $parseable = (array) config('services.quotes.parseable_extensions', ['pdf', 'xlsx', 'xls', 'docx']);

        foreach ($message->attachments as $att) {
            $ext = strtolower((string) pathinfo((string) $att->filename, PATHINFO_EXTENSION));
            if (! in_array($ext, $parseable, true)) {
                continue;
            }
            // Письмо может нести И КП, И счёт одновременно (M-2026-3456). Тип письма
            // (priority invoice > quotation) задаёт статус заявки, но КАЖДОЕ вложение
            // парсится по СВОЕМУ типу: «Счет …» → outbound_invoice (→ Invoice),
            // «Предложение …» → outbound_quotation_full. Файлы, что по имени не
            // самоопределяются (спецификация, doc.pdf), наследуют тип письма.
            $attType = $this->outboundDetector->classifyAttachmentByFilename((string) $att->filename)
                ?? $letterType;
            try {
                ParseOutboundQuoteJob::dispatch($att->id, $attType->value, false);
            } catch (\Throwable $e) {
                Log::warning('MailRouter: dispatch outbound quote parser failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'attachment_id' => $att->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
