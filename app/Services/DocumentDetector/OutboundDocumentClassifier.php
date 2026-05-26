<?php

namespace App\Services\DocumentDetector;

use App\Enums\DetectorType;
use App\Enums\RequestStatus;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Prompts\Mail\ClassifyOutboundDocumentPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * LLM-вариант outbound DocumentDetector (Foundation §7.1, Phase 2 LLM).
 *
 * Используется как fallback после rule-based `OutboundDocumentDetector`:
 *   rule_based.analyze() → null  ⇒  llm.classify()
 *
 * Rule-based быстр и бесплатен, ловит подавляющее большинство «КП.pdf» /
 * «Счёт.xlsx» / «прошу уточнить». LLM добивает edge-cases где имя файла
 * не содержит ключевых слов («Предложение МЗ-355319.pdf»), body пуст
 * (HTML-only из веб-интерфейса) или текст использует нестандартные
 * формулировки.
 *
 * Возвращает тот же контракт, что rule-based:
 *   ['type' => DetectorType, 'confidence' => float, 'signals' => array]
 * или null если LLM сказал `other` / confidence < 0.6 / API сбой.
 */
class OutboundDocumentClassifier
{
    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly ClassifyOutboundDocumentPrompt $prompt,
    ) {
    }

    /**
     * @return ?array{type: DetectorType, confidence: float, signals: array<string, mixed>}
     */
    public function classify(EmailMessage $message, Request $request): ?array
    {
        // Терминал / pause — нечего двигать (consistency с rule-based).
        if ($request->status->isTerminal() || $request->status === RequestStatus::Paused) {
            return null;
        }

        if (! config('services.openai.api_key')) {
            return null;
        }

        $message->loadMissing('attachments');

        $messages = $this->prompt->build($message, $request);
        $model = (string) config('services.openai.outbound_classifier_model', 'gpt-4o-mini');

        try {
            $response = $this->openai->chat($messages, $model, [
                'temperature' => 0,
                'max_tokens' => 200,
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('OutboundDocumentClassifier: OpenAI call failed', [
                'email_message_id' => $message->id,
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $content = (string) ($response['content'] ?? '');
        $parsed = json_decode($content, true);
        if (! is_array($parsed) || ! isset($parsed['type'])) {
            Log::warning('OutboundDocumentClassifier: invalid JSON', [
                'email_message_id' => $message->id,
                'raw' => mb_substr($content, 0, 400),
            ]);

            return null;
        }

        $type = $this->mapType((string) $parsed['type']);
        $confidence = isset($parsed['confidence'])
            ? max(0.0, min(1.0, (float) $parsed['confidence']))
            : 0.0;
        $reasoning = isset($parsed['reasoning']) && is_string($parsed['reasoning'])
            ? mb_substr(trim($parsed['reasoning']), 0, 500)
            : null;

        // Confidence-guard и other → null (нет события).
        if ($type === null || $confidence < 0.6) {
            Log::info('OutboundDocumentClassifier: low confidence or other, skip', [
                'email_message_id' => $message->id,
                'type' => $parsed['type'] ?? null,
                'confidence' => $confidence,
            ]);

            return null;
        }

        Log::info('OutboundDocumentClassifier: classified', [
            'email_message_id' => $message->id,
            'request_id' => $request->id,
            'type' => $type->value,
            'confidence' => $confidence,
            'reasoning' => $reasoning,
            'usage' => $response['usage'] ?? [],
        ]);

        $result = [
            'type' => $type,
            'confidence' => $confidence,
            'signals' => [
                'source' => 'llm',
                'reasoning' => $reasoning,
            ],
        ];

        // Для type=declined — пробрасываем suggested_closed_lost_reason
        // и cited_phrase на верхний уровень result'а, чтобы MailRouter
        // положил их в payload жадно (AiDecisionService::apply читает
        // payload[suggested_closed_lost_reason] / [cited_phrase] для
        // ClosedLost-перехода).
        if ($type === DetectorType::OutboundDeclined) {
            $reason = isset($parsed['suggested_closed_lost_reason'])
                && is_string($parsed['suggested_closed_lost_reason'])
                ? trim($parsed['suggested_closed_lost_reason'])
                : 'off_topic';
            $result['suggested_closed_lost_reason'] = $reason !== '' ? $reason : 'off_topic';
            if (isset($parsed['cited_phrase']) && is_string($parsed['cited_phrase'])) {
                $result['cited_phrase'] = mb_substr(trim($parsed['cited_phrase']), 0, 500);
            }
        }

        return $result;
    }

    private function mapType(string $raw): ?DetectorType
    {
        return match (mb_strtolower(trim($raw))) {
            'quotation' => DetectorType::OutboundQuotationFull,
            'invoice' => DetectorType::OutboundInvoice,
            'clarification' => DetectorType::OutboundClarification,
            'declined' => DetectorType::OutboundDeclined,
            default => null, // other, unknown
        };
    }
}
