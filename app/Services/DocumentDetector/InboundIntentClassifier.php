<?php

namespace App\Services\DocumentDetector;

use App\Enums\DetectorType;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Prompts\Mail\ClassifyClientResponsePrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Inbound-классификатор клиентских ответов (Foundation §7.2).
 *
 * Триггер: входящее письмо, привязанное к Request в одном из ELIGIBLE_STATUSES:
 *   new / assigned / in_progress (ранние — но автоприменяется ТОЛЬКО decline,
 *   чтобы ловить отмену ДО КП), quoted / under_review / postponed_until /
 *   awaiting_client_clarification / awaiting_invoice / invoiced.
 *   awaiting_invoice/invoiced — ловить отказ после счёта (auto-close lost).
 * LLM (gpt-4o-mini) определяет intent клиента, возвращает структурированный
 * ответ; сервис мапит intent → DetectorType и возвращает payload для
 * AiDecisionService::recordSuggestion.
 *
 * Confidence < 0.6 → принудительно unclear (защита в самом промпте,
 * дублируется в коде на всякий случай).
 */
class InboundIntentClassifier
{
    /**
     * Статусы Request, в которых имеет смысл классифицировать reply.
     * Для остальных статусов inbound — это либо новая заявка (handled
     * IncomingMailProcessor), либо нерелевантный шум.
     *
     * @var array<int, RequestStatus>
     */
    private const ELIGIBLE_STATUSES = [
        // Ранние рабочие статусы (до квотирования) — чтобы ловить явную отмену/
        // отказ клиента ДО отправки КП (кейс M-2026-4710 «задвоила запрос,
        // закрывайте»). В них автоприменяется ТОЛЬКО decline (см. EARLY_STATUSES
        // ниже) — прочие интенты тут преждевременны.
        RequestStatus::New,
        RequestStatus::Assigned,
        RequestStatus::InProgress,
        RequestStatus::Quoted,
        RequestStatus::UnderReview,
        RequestStatus::PostponedUntil,
        RequestStatus::AwaitingClientClarification,
        RequestStatus::AwaitingInvoice,
        RequestStatus::Invoiced,
    ];

    /**
     * Ранние статусы (до квотирования): классифицируем reply, но автоприменяем
     * ТОЛЬКО decline/new_request — остальные интенты (счёт/доп.позиции/
     * согласование/отложить) преждевременны, оставляем менеджеру.
     *
     * @var array<int, RequestStatus>
     */
    private const EARLY_STATUSES = [
        RequestStatus::New,
        RequestStatus::Assigned,
        RequestStatus::InProgress,
    ];

    private const CONFIDENCE_FLOOR = 0.6;

    /**
     * Потолок confidence для invoice_request БЕЗ слова «счёт» в письме: ниже
     * порога auto-apply (detector.confidence_threshold=0.85), выше floor(0.6) —
     * решение остаётся подсказкой, заявку в «ждёт счёт» авто не переводит.
     */
    private const INVOICE_NO_TOKEN_CONF_CAP = 0.7;

    /** Токены явного запроса счёта в тексте письма клиента. */
    private const INVOICE_TOKENS_RE = '/сч[её]т|на\s+оплат|invoice|инвойс/iu';

    /** Маркеры начала процитированной истории — режем перед проверкой токена. */
    private const QUOTE_MARKERS_RE = [
        '/-{10,}/u',
        '/\d{1,2}\.\d{2}\.\d{4}[ ,][^:]{0,60}?(?:пишет|написал(?:а)?|wrote)\s*:/iu',
        '/\d{1,2}\.\d{2}\.\d{4},\s*\d{1,2}:\d{2},/u',
        '/\bКому\s*:/u',
        '/\b(?:From|От\s+кого)\s*:/u',
        '/^\s*>\s?/mu',
    ];

    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly ClassifyClientResponsePrompt $prompt,
        private readonly \App\Services\Mail\InternalSenderDetector $internal = new \App\Services\Mail\InternalSenderDetector(),
    ) {}

    public function isApplicable(Request $request): bool
    {
        return in_array($request->status, self::ELIGIBLE_STATUSES, true);
    }

    /**
     * @return ?array{type: ?DetectorType, confidence: float, payload: array<string, mixed>}
     *                                                                                       type === null с payload.intent === 'new_request' — сигнал «это отдельная
     *                                                                                       новая заявка в треде» (обрабатывает MailRouter, не AiDecisionService).
     */
    public function classify(EmailMessage $message, Request $request): ?array
    {
        if (! $this->isApplicable($request)) {
            return null;
        }

        // Внутренняя переписка (отправитель И все получатели — наши) НЕ влияет
        // на статус заявки: это общение сотрудников, а не реакция заказчика.
        // Влияют только письма, где хотя бы на одной стороне внешняя сторона
        // (заказчик). Кейс M-2026-6071: письмо руководителя менеджеру (оба
        // @myzip.ru) авто-переводило «КП отправлено» → «На согласовании».
        // Помечаем intent_classified_at, чтобы догоняющий крон не перебирал
        // письмо снова.
        if ($this->internal->isInternalOnly($message)) {
            EmailMessage::query()->whereKey($message->id)->update(['intent_classified_at' => now()]);
            Log::info('InboundIntentClassifier: internal-only email — status not affected', [
                'email_message_id' => $message->id,
                'request_id' => $request->id,
                'from' => $message->from_email,
            ]);

            return null;
        }

        $messages = $this->prompt->build($message, $request);

        try {
            $result = $this->openai->chat(
                $messages,
                config('services.openai.intent_model', 'gpt-4o-mini'),
                [
                    'temperature' => 0,
                    'max_tokens' => 600,
                    'response_format' => ['type' => 'json_object'],
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('InboundIntentClassifier: LLM call failed (non-fatal)', [
                'email_message_id' => $message->id,
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $parsed = json_decode($result['content'] ?? '', true);
        if (! is_array($parsed) || ! isset($parsed['intent'])) {
            Log::warning('InboundIntentClassifier: invalid LLM response', [
                'email_message_id' => $message->id,
                'request_id' => $request->id,
                'raw' => mb_substr((string) ($result['content'] ?? ''), 0, 400),
            ]);

            return null;
        }

        // LLM ОТВЕТИЛ (валидный JSON) — помечаем письмо как классифицированное,
        // даже если intent окажется unclear/non-actionable. Транзиентный сбой
        // выше (429/quota) сюда не доходит (return null без отметки), поэтому
        // догоняющий крон повторит ТОЛЬКО реально пролетевшие письма.
        EmailMessage::query()->whereKey($message->id)->update(['intent_classified_at' => now()]);

        $intent = (string) $parsed['intent'];
        $confidence = (float) ($parsed['confidence'] ?? 0);

        // Floor: ниже порога — unclear.
        if ($confidence < self::CONFIDENCE_FLOOR) {
            $intent = 'unclear';
        }

        $type = $this->intentToDetectorType($intent, $request->status);

        // new_request не мапится в DetectorType (это не переход статуса, а
        // создание ОТДЕЛЬНОЙ заявки). Пробрасываем сигнал через payload.intent —
        // MailRouter сам разветвит (spin-off). Прочие intent'ы без типа → null.
        if ($type === null && $intent !== 'new_request') {
            return null;
        }

        // Дополнительная защита: clarification_response уместен ТОЛЬКО при
        // awaiting_client_clarification. Если LLM ошибся — downgrade в unclear.
        if ($type === DetectorType::InboundClarificationResponse
            && $request->status !== RequestStatus::AwaitingClientClarification
        ) {
            $type = DetectorType::InboundUnclear;
        }

        // Детерминированный гард авто-счёта: invoice_request авто-переводит
        // заявку в awaiting_invoice («ждёт счёт»). Но модель охотно принимает
        // любое подтверждение («Да», «да, такой нужен», «верно», «вы же уже
        // выставляли КП») за согласие на счёт. Если в НОВОМ тексте письма
        // (без цитаты) нет явного упоминания счёта/оплаты — НЕ авто-применяем:
        // роняем confidence ниже порога auto-apply, решение остаётся подсказкой
        // менеджеру. Кейсы M-2026-4607/5074/5978/5857. Промптом это не лечится —
        // у модели сильный прайор «подтверждение после КП = счёт».
        if ($type === DetectorType::InboundInvoiceRequest
            && ! $this->mentionsInvoiceToken($message)
            && $confidence > self::INVOICE_NO_TOKEN_CONF_CAP
        ) {
            $confidence = self::INVOICE_NO_TOKEN_CONF_CAP;
        }

        // В ранних статусах (до квотирования) автоприменяем ТОЛЬКО decline
        // (явная отмена/отказ). Остальные интенты (счёт/доп.позиции/согласование/
        // отложить) на ранней стадии преждевременны — downgrade в unclear,
        // решает менеджер. new_request (type=null) проходит — это спин-офф.
        if (in_array($request->status, self::EARLY_STATUSES, true)
            && $type !== null
            && $type !== DetectorType::InboundDecline
        ) {
            $type = DetectorType::InboundUnclear;
        }

        $payload = [
            'intent' => $intent,
            'reasoning' => $parsed['reasoning'] ?? null,
            'suggested_resume_date' => $parsed['suggested_resume_date'] ?? null,
            'suggested_closed_lost_reason' => $parsed['suggested_closed_lost_reason'] ?? null,
            'cited_phrase' => $parsed['cited_phrase'] ?? null,
            'usage' => $result['usage'] ?? null,
        ];

        // Если LLM прислал дату для postponement — пробросим её в payload
        // как postponed_until для использования при apply (transitionTo с
        // payload, AttentionService прочитает его).
        if (! empty($parsed['suggested_resume_date'])
            && $type === DetectorType::InboundPostponed
        ) {
            $payload['transition_payload'] = [
                'postponed_until' => $parsed['suggested_resume_date'],
            ];
        }

        Log::info('InboundIntentClassifier: classified', [
            'email_message_id' => $message->id,
            'request_id' => $request->id,
            'intent' => $intent,
            'type' => $type?->value,
            'confidence' => $confidence,
        ]);

        return [
            'type' => $type,
            'confidence' => $confidence,
            'payload' => $payload,
        ];
    }

    /**
     * Есть ли в НОВОМ тексте письма клиента (без процитированной истории)
     * явное упоминание счёта/оплаты. Цитату режем — иначе «счёт» из нашего же
     * КП/предыдущего письма в цитате даст ложное срабатывание.
     */
    private function mentionsInvoiceToken(EmailMessage $message): bool
    {
        $text = trim((string) ($message->body_plain ?: strip_tags((string) $message->body_html)));
        if ($text === '') {
            return false;
        }

        // Срезать цитату по самому раннему маркеру (построчно-независимо).
        $cut = mb_strlen($text);
        foreach (self::QUOTE_MARKERS_RE as $re) {
            if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                $charPos = mb_strlen(substr($text, 0, $m[0][1]));
                $cut = min($cut, $charPos);
            }
        }
        if ($cut >= 12) {
            $text = mb_substr($text, 0, $cut);
        }

        return preg_match(self::INVOICE_TOKENS_RE, $text) === 1;
    }

    private function intentToDetectorType(string $intent, RequestStatus $currentStatus): ?DetectorType
    {
        return match ($intent) {
            'under_review_acknowledgment' => DetectorType::InboundUnderReview,
            'postponement_request' => DetectorType::InboundPostponed,
            'invoice_request' => DetectorType::InboundInvoiceRequest,
            'decline_with_reason' => DetectorType::InboundDecline,
            'clarification_response' => DetectorType::InboundClarificationResponse,
            'additional_items' => DetectorType::InboundExtension,
            'unclear' => DetectorType::InboundUnclear,
            // 'new_request' намеренно не мапится — обрабатывается отдельно.
            default => null,
        };
    }
}
