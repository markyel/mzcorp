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
        private readonly TrustedPartnerOverride $partnerOverride,
        private readonly InternalSenderDetector $internalSender,
        private readonly UnintendedRecipientDetector $unintendedRecipient,
        private readonly PostSaleFulfillmentDetector $postSaleFulfillment,
        private readonly \App\Services\AI\OpenAiCircuitBreaker $circuitBreaker,
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

        // Internal-sender short-circuit: письма от наших же сотрудников
        // (домен myzip.ru / совпадает с Mailbox.email или User.email) —
        // это внутренняя переписка, не клиентская заявка. Детерминированно
        // ставим `irrelevant`, минуя LLM. Кейс M-2026-0161.
        $internalReason = $this->internalSender->detect($message);
        if ($internalReason !== null) {
            $category = EmailCategory::Irrelevant;
            $reasoning = 'Internal sender: ' . $internalReason;
            $message->forceFill([
                'category' => $category->value,
                'category_confidence' => 1.0,
                'category_intent' => null,
                'category_reasoning' => $reasoning,
                'categorized_at' => now(),
            ])->save();

            Log::info('MailCategoryClassifier: internal sender override', [
                'email_message_id' => $message->id,
                'from_email' => $message->from_email,
                'reason' => $internalReason,
            ]);

            return [
                'category' => $category,
                'confidence' => 1.0,
                'intent' => null,
                'reasoning' => $reasoning,
            ];
        }

        // Trusted-partner short-circuit: для known партнёрских систем
        // (Liftway-saas) детерминированно ставим client_request, минуя LLM.
        // Категоризатор формально прав («запрос от маркетплейса»), но
        // бизнес-факт: это client_request для нашего workflow.
        $override = $this->partnerOverride->resolve($message);
        if ($override !== null) {
            $category = $override['category'];
            $reasoning = 'Trusted partner override: ' . $override['partner'];
            $message->forceFill([
                'category' => $category->value,
                'category_confidence' => 1.0,
                'category_intent' => null,
                'category_reasoning' => $reasoning,
                'categorized_at' => now(),
            ])->save();

            Log::info('MailCategoryClassifier: trusted partner override', [
                'email_message_id' => $message->id,
                'partner' => $override['partner'],
                'category' => $category->value,
            ]);

            return [
                'category' => $category,
                'confidence' => 1.0,
                'intent' => null,
                'reasoning' => $reasoning,
            ];
        }

        // Unintended-recipient short-circuit: письмо не адресовано ни одному
        // из наших ящиков/пользователей/доменов И не относится к нашему
        // известному треду (in_reply_to / references — пусто или ссылается
        // на Message-ID, которого у нас в БД нет). Такие письма попадают к
        // нам случайно: BCC, forward со стороны Yandex, mailing list. См.
        // M-2026-1491 (info@unisystem.si → valentina.larosa@moris.it,
        // Vasukhno был BCC, gpt-4o уверенно сказал client_request).
        $unintendedReason = $this->unintendedRecipient->detect($message);
        if ($unintendedReason !== null) {
            $category = EmailCategory::Irrelevant;
            $reasoning = 'Unintended recipient: ' . $unintendedReason;
            $message->forceFill([
                'category' => $category->value,
                'category_confidence' => 1.0,
                'category_intent' => null,
                'category_reasoning' => $reasoning,
                'categorized_at' => now(),
            ])->save();

            Log::info('MailCategoryClassifier: unintended recipient override', [
                'email_message_id' => $message->id,
                'from_email' => $message->from_email,
                'to' => $message->to_recipients,
                'cc' => $message->cc_recipients,
                'reason' => $unintendedReason,
            ]);

            return [
                'category' => $category,
                'confidence' => 1.0,
                'intent' => null,
                'reasoning' => $reasoning,
            ];
        }

        // Post-sale fulfillment short-circuit: «отгрузите / поставьте на
        // комплектацию» уже оплаченный заказ — это post_sale, а не новая
        // заявка. gpt-4o на таких терсовых письмах систематически ошибается
        // в client_request (тикеты M-2026-2706 / M-2026-2762). Детектор
        // срабатывает только при наличии оплаченного заказа клиента и
        // отсутствии запроса цены/количеств — иначе решает LLM.
        $fulfillmentReason = $this->postSaleFulfillment->detect($message);
        if ($fulfillmentReason !== null) {
            $category = EmailCategory::PostSale;
            $reasoning = 'Post-sale fulfillment: ' . $fulfillmentReason;
            $message->forceFill([
                'category' => $category->value,
                'category_confidence' => 1.0,
                'category_intent' => null,
                'category_reasoning' => $reasoning,
                'categorized_at' => now(),
            ])->save();

            Log::info('MailCategoryClassifier: post-sale fulfillment override', [
                'email_message_id' => $message->id,
                'from_email' => $message->from_email,
                'reason' => $fulfillmentReason,
            ]);

            return [
                'category' => $category,
                'confidence' => 1.0,
                'intent' => null,
                'reasoning' => $reasoning,
            ];
        }

        if (! config('services.openai.api_key')) {
            Log::warning('MailCategoryClassifier: OPENAI_API_KEY not set', [
                'email_message_id' => $message->id,
            ]);

            return $this->emptyResult();
        }

        // Circuit-breaker гейт: если N подряд OpenAI-вызовов упали на 429/503/
        // insufficient_quota, не лезем в API ещё M минут (см. OpenAiCircuitBreaker).
        // Это экономит лишние списания у прокси-провайдера + не путает scheduler
        // mail:categorize --all (он подберёт письмо когда circuit закроется).
        if ($this->circuitBreaker->isOpen()) {
            Log::info('MailCategoryClassifier: skip — circuit breaker open', [
                'email_message_id' => $message->id,
                'remaining_minutes' => $this->circuitBreaker->remainingMinutes(),
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
            $this->circuitBreaker->recordSuccess();
        } catch (\Throwable $e) {
            Log::error('MailCategoryClassifier: OpenAI call failed', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
            if ($this->circuitBreaker->isTransientError($e)) {
                $this->circuitBreaker->recordFailure($e->getMessage(), [
                    'email_message_id' => $message->id,
                    'model' => $model,
                ]);
            }

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
