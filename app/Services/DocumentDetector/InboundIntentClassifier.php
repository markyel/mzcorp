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
 * Триггер: входящее письмо, привязанное к Request в одном из статусов
 *   quoted / under_review / postponed_until / awaiting_client_clarification /
 *   awaiting_invoice / invoiced (см. ELIGIBLE_STATUSES). Последние два — чтобы
 *   ловить отказ клиента после выставления счёта (auto-close lost).
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
        RequestStatus::Quoted,
        RequestStatus::UnderReview,
        RequestStatus::PostponedUntil,
        RequestStatus::AwaitingClientClarification,
        RequestStatus::AwaitingInvoice,
        RequestStatus::Invoiced,
    ];

    private const CONFIDENCE_FLOOR = 0.6;

    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly ClassifyClientResponsePrompt $prompt,
    ) {
    }

    public function isApplicable(Request $request): bool
    {
        return in_array($request->status, self::ELIGIBLE_STATUSES, true);
    }

    /**
     * @return ?array{type: ?DetectorType, confidence: float, payload: array<string, mixed>}
     *   type === null с payload.intent === 'new_request' — сигнал «это отдельная
     *   новая заявка в треде» (обрабатывает MailRouter, не AiDecisionService).
     */
    public function classify(EmailMessage $message, Request $request): ?array
    {
        if (! $this->isApplicable($request)) {
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
