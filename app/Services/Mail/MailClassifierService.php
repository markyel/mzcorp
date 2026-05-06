<?php

namespace App\Services\Mail;

use App\Enums\EmailClassification;
use App\Models\EmailMessage;
use App\Prompts\Mail\ClassifyIncomingPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Классификация входящего письма через OpenAI gpt-4o-mini.
 *
 * Foundation §2.4. Заполняет поля EmailMessage:
 *   - ai_classification             (enum value string)
 *   - ai_classification_confidence  (float 0..1)
 *   - classified_at                 (timestamp)
 *
 * Идемпотентность: если classified_at != null и ! $force — пропускаем.
 *
 * При ошибке (нет API key, парсер сломал JSON, retry exhausted) — в лог,
 * но не валим вызывающий job.
 */
class MailClassifierService
{
    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly ClassifyIncomingPrompt $prompt,
    ) {
    }

    public function classify(EmailMessage $message, bool $force = false): ?EmailClassification
    {
        if (! $force && $message->classified_at !== null) {
            return $message->ai_classification
                ? EmailClassification::tryFrom($message->ai_classification)
                : null;
        }

        if (! config('services.openai.api_key')) {
            Log::warning('MailClassifier: OPENAI_API_KEY not set — skipping classification', [
                'email_message_id' => $message->id,
            ]);

            return null;
        }

        $messages = $this->prompt->build($message);
        $model = (string) config('services.openai.mail_classifier_model', 'gpt-4o-mini');

        try {
            $response = $this->openai->chat($messages, $model, [
                'temperature' => 0,
                'max_tokens' => 200,
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (\Throwable $e) {
            Log::error('MailClassifier: OpenAI call failed', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $content = (string) ($response['content'] ?? '');
        $parsed = json_decode($content, true);

        if (! is_array($parsed) || ! isset($parsed['classification'])) {
            Log::warning('MailClassifier: invalid JSON from model', [
                'email_message_id' => $message->id,
                'raw' => mb_substr($content, 0, 300),
            ]);

            return null;
        }

        $classification = EmailClassification::tryFrom((string) $parsed['classification']);
        if ($classification === null) {
            Log::warning('MailClassifier: unknown classification value', [
                'email_message_id' => $message->id,
                'value' => $parsed['classification'],
            ]);
            $classification = EmailClassification::Other;
        }

        $confidence = isset($parsed['confidence']) ? max(0.0, min(1.0, (float) $parsed['confidence'])) : null;

        $message->forceFill([
            'ai_classification' => $classification->value,
            'ai_classification_confidence' => $confidence,
            'classified_at' => now(),
        ])->save();

        Log::info('Mail classified', [
            'email_message_id' => $message->id,
            'class' => $classification->value,
            'confidence' => $confidence,
            'subject' => mb_substr((string) $message->subject, 0, 80),
            'usage' => $response['usage'] ?? [],
        ]);

        return $classification;
    }
}
