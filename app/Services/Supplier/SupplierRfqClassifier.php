<?php

namespace App\Services\Supplier;

use App\Models\EmailMessage;
use App\Prompts\Mail\ClassifySupplierRfqPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * LLM-проверка: наше исходящее получателю-поставщику — это запрос расценки
 * (RFQ) или ответ ему как клиенту? Второй гейт после реестра поставщиков
 * (SupplierRegistry). gpt-4o-mini, fail-safe: ошибка/низкая уверенность →
 * НЕ RFQ (не регистрируем тред — лучше пропустить, чем ошибочно заглушить).
 */
class SupplierRfqClassifier
{
    private const CONFIDENCE_FLOOR = 0.6;

    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly ClassifySupplierRfqPrompt $prompt,
    ) {
    }

    /**
     * @return array{is_rfq: bool, confidence: float, reasoning: ?string}
     */
    public function classify(EmailMessage $outbound): array
    {
        $fail = ['is_rfq' => false, 'confidence' => 0.0, 'reasoning' => null];

        try {
            $result = $this->openai->chat(
                $this->prompt->build($outbound),
                config('services.openai.intent_model', 'gpt-4o-mini'),
                [
                    'temperature' => 0,
                    'max_tokens' => 300,
                    'response_format' => ['type' => 'json_object'],
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('SupplierRfqClassifier: LLM call failed (non-fatal)', [
                'email_message_id' => $outbound->id,
                'error' => $e->getMessage(),
            ]);

            return $fail;
        }

        $parsed = json_decode($result['content'] ?? '', true);
        if (! is_array($parsed) || ! array_key_exists('is_rfq', $parsed)) {
            return $fail;
        }

        $confidence = (float) ($parsed['confidence'] ?? 0);
        $isRfq = (bool) $parsed['is_rfq'] && $confidence >= self::CONFIDENCE_FLOOR;

        return [
            'is_rfq' => $isRfq,
            'confidence' => $confidence,
            'reasoning' => isset($parsed['reasoning']) ? (string) $parsed['reasoning'] : null,
        ];
    }
}
