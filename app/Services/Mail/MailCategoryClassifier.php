<?php

namespace App\Services\Mail;

use App\Enums\EmailCategory;
use App\Models\EmailMessage;
use App\Prompts\Mail\CategorizeIncomingPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1.8c: расширенный AI-классификатор писем (drop-in из LazyLift Flow 1).
 *
 * Заполняет в EmailMessage:
 *   - category               (App\Enums\EmailCategory)
 *   - category_confidence    (float 0..1)
 *   - category_intent        (confirm_order | null)
 *   - category_reasoning     (string)
 *   - categorized_at         (timestamp)
 *
 * НЕ ТРОГАЕТ старые ai_classification / classified_at — они продолжают
 * жить для MailRoutingRule (Phase 1.5).
 *
 * Используется gpt-4o (default model OpenAIChatService), потому что
 * классификация сложная с reasoning. confidence < 0.7 — клиент должен
 * руками разобрать.
 *
 * Идемпотентность: skip если categorized_at != null и !$force.
 */
class MailCategoryClassifier
{
    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly CategorizeIncomingPrompt $prompt,
    ) {
    }

    /**
     * @return array{category: ?EmailCategory, confidence: ?float, intent: ?string, reasoning: ?string}
     */
    public function categorize(EmailMessage $message, bool $force = false): array
    {
        if (! $force && $message->categorized_at !== null) {
            return [
                'category' => $message->category ? EmailCategory::tryFrom($message->category) : null,
                'confidence' => $message->category_confidence !== null ? (float) $message->category_confidence : null,
                'intent' => $message->category_intent,
                'reasoning' => $message->category_reasoning,
            ];
        }

        if (! config('services.openai.api_key')) {
            Log::warning('MailCategoryClassifier: OPENAI_API_KEY not set', [
                'email_message_id' => $message->id,
            ]);

            return $this->emptyResult();
        }

        // Eager-load attachments + mailbox для prompt builder.
        $message->loadMissing(['attachments', 'mailbox']);

        $messages = $this->prompt->build($message);
        $model = (string) config('services.openai.category_model', 'gpt-4o');

        try {
            $response = $this->openai->chat($messages, $model, [
                'temperature' => 0,
                'max_tokens' => 400,
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (\Throwable $e) {
            Log::error('MailCategoryClassifier: OpenAI call failed', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return $this->emptyResult();
        }

        $content = (string) ($response['content'] ?? '');
        $parsed = json_decode($content, true);

        if (! is_array($parsed) || ! isset($parsed['type'])) {
            Log::warning('MailCategoryClassifier: invalid JSON', [
                'email_message_id' => $message->id,
                'raw' => mb_substr($content, 0, 400),
            ]);

            return $this->emptyResult();
        }

        $category = EmailCategory::tryFrom((string) $parsed['type']);
        if ($category === null) {
            Log::warning('MailCategoryClassifier: unknown category', [
                'email_message_id' => $message->id,
                'value' => $parsed['type'],
            ]);
            $category = EmailCategory::Irrelevant;
        }

        $confidence = isset($parsed['confidence'])
            ? max(0.0, min(1.0, (float) $parsed['confidence']))
            : null;

        $intent = ! empty($parsed['intent']) && is_string($parsed['intent'])
            ? mb_substr(trim($parsed['intent']), 0, 32)
            : null;
        // AI иногда возвращает строку "null" — защита.
        if ($intent === 'null' || $intent === '') {
            $intent = null;
        }

        $reasoning = isset($parsed['reasoning']) && is_string($parsed['reasoning'])
            ? mb_substr(trim($parsed['reasoning']), 0, 1000)
            : null;

        $message->forceFill([
            'category' => $category->value,
            'category_confidence' => $confidence,
            'category_intent' => $intent,
            'category_reasoning' => $reasoning,
            'categorized_at' => now(),
        ])->save();

        Log::info('Mail categorized', [
            'email_message_id' => $message->id,
            'category' => $category->value,
            'confidence' => $confidence,
            'intent' => $intent,
            'subject' => mb_substr((string) $message->subject, 0, 80),
            'usage' => $response['usage'] ?? [],
        ]);

        return [
            'category' => $category,
            'confidence' => $confidence,
            'intent' => $intent,
            'reasoning' => $reasoning,
        ];
    }

    private function emptyResult(): array
    {
        return ['category' => null, 'confidence' => null, 'intent' => null, 'reasoning' => null];
    }
}
