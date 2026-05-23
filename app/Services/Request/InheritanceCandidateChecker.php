<?php

namespace App\Services\Request;

use App\Models\EmailMessage;
use App\Models\Request as RequestModel;
use App\Prompts\Request\CheckInheritanceCandidatePrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2.1 — LLM-проверка гипотезы наследования.
 *
 * Вход: новая Request, кандидат-родитель (closed_lost), исходное письмо.
 * Выход: {is_continuation: bool, confidence: float, reasoning: string}
 *
 * Модель gpt-4o-mini (бинарная задача, не нужна сила -4o).
 * Промпт — `CheckInheritanceCandidatePrompt`.
 *
 * Дёргается из `CheckInheritanceJob` после ParseRequestItemsJob.
 */
class InheritanceCandidateChecker
{
    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly CheckInheritanceCandidatePrompt $prompt,
    ) {
    }

    /**
     * @return array{is_continuation: bool, confidence: float, reasoning: ?string}|null
     *         null если LLM сломался или вернул мусор — caller трактует как «не подтверждено».
     */
    public function check(
        EmailMessage $inboundMessage,
        RequestModel $newRequest,
        RequestModel $candidateArchive,
    ): ?array {
        if (! config('services.openai.api_key')) {
            Log::warning('InheritanceCandidateChecker: OPENAI_API_KEY not set', [
                'new_request_id' => $newRequest->id,
            ]);

            return null;
        }

        $messages = $this->prompt->build($inboundMessage, $newRequest, $candidateArchive);
        $model = (string) config('services.openai.inheritance_check_model', 'gpt-4o-mini');

        try {
            $response = $this->openai->chat($messages, $model, [
                'temperature' => 0,
                'max_tokens' => 300,
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (\Throwable $e) {
            Log::error('InheritanceCandidateChecker: OpenAI call failed', [
                'new_request_id' => $newRequest->id,
                'candidate_archive_id' => $candidateArchive->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $content = (string) ($response['content'] ?? '');
        $parsed = json_decode($content, true);

        if (! is_array($parsed) || ! isset($parsed['is_continuation'])) {
            Log::warning('InheritanceCandidateChecker: invalid JSON', [
                'new_request_id' => $newRequest->id,
                'raw' => mb_substr($content, 0, 300),
            ]);

            return null;
        }

        $isContinuation = (bool) $parsed['is_continuation'];
        $confidence = isset($parsed['confidence'])
            ? max(0.0, min(1.0, (float) $parsed['confidence']))
            : 0.0;
        $reasoning = isset($parsed['reasoning']) && is_string($parsed['reasoning'])
            ? mb_substr(trim($parsed['reasoning']), 0, 500)
            : null;

        Log::info('InheritanceCandidateChecker: result', [
            'new_request_id' => $newRequest->id,
            'candidate_archive_id' => $candidateArchive->id,
            'is_continuation' => $isContinuation,
            'confidence' => $confidence,
            'reasoning' => $reasoning,
            'usage' => $response['usage'] ?? [],
        ]);

        return [
            'is_continuation' => $isContinuation,
            'confidence' => $confidence,
            'reasoning' => $reasoning,
        ];
    }
}
