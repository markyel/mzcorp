<?php

namespace App\Services\Mail;

use App\Enums\MailDirection;
use App\Enums\MailRuleActionType;
use App\Models\EmailMessage;
use App\Models\MailRoutingRule;
use App\Models\RoutedMail;
use App\Services\DocumentDetector\AiDecisionService;
use App\Services\DocumentDetector\InboundIntentClassifier;
use App\Services\DocumentDetector\OutboundDocumentDetector;
use Illuminate\Support\Facades\Log;

/**
 * Pipeline маршрутизации одного письма:
 *   inbound  → loop-guard → categorize → reply-linker → rules engine
 *              → AI fallback → IncomingProcessor → apply rules.
 *   outbound → OutgoingMailLinker (header threading + subject code +
 *              recipient open-request match). Без правил, без AI-классификатора.
 *
 * Foundation §1.5 pipeline.
 */
class MailRouter
{
    public function __construct(
        private readonly MailRoutingRuleEngine $engine,
        private readonly MailLabelService $labels,
        private readonly MailForwarder $forwarder,
        private readonly MailClassifierService $classifier,
        private readonly IncomingMailProcessor $incoming,
        private readonly MailCategoryClassifier $categorizer,
        private readonly InboundReplyLinker $replyLinker,
        private readonly OutgoingMailLinker $outgoingLinker,
        private readonly OutboundDocumentDetector $outboundDetector,
        private readonly InboundIntentClassifier $inboundClassifier,
        private readonly AiDecisionService $aiDecisions,
    ) {
    }

    public function route(EmailMessage $message): void
    {
        // Phase 1.9 outbound: исходящие из Sent — линкуем к существующей
        // Request, не пропускаем через categorize/rules/IncomingProcessor
        // (это наше письмо, не клиентский запрос). Отдельная короткая ветка.
        if ($message->direction === MailDirection::Outbound) {
            try {
                $linkedRequest = $this->outgoingLinker->tryLink($message);
            } catch (\Throwable $e) {
                $linkedRequest = null;
                Log::warning('MailRouter: outgoing linker failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Phase 4 (Foundation §7.1): outbound document detector.
            // Сработает только если linker уже привязал письмо к Request —
            // иначе непонятно к чему относится «КП» (общая переписка с
            // клиентом по другим заявкам, маркетинг и т.п.).
            if ($linkedRequest !== null) {
                try {
                    $detected = $this->outboundDetector->analyze($message->fresh(), $linkedRequest);
                    if ($detected !== null) {
                        $this->aiDecisions->recordSuggestion(
                            $detected['type'],
                            $linkedRequest,
                            $message,
                            (float) $detected['confidence'],
                            ['signals' => $detected['signals']],
                        );
                    }
                } catch (\Throwable $e) {
                    Log::warning('MailRouter: outbound document detector failed (non-fatal)', [
                        'email_message_id' => $message->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return;
        }

        if ($message->direction !== MailDirection::Inbound) {
            return;
        }

        // Антициклическая защита: письма, уже когда-то пересланные нашим
        // MailForwarder'ом, имеют заголовок X-MyLift-Forwarded или префикс
        // [MyLift forward] в subject. Не маршрутизируем их повторно.
        if ($this->isLoopMessage($message)) {
            $this->recordLoopSkipped($message);

            return;
        }

        // Phase 1.8c: категоризация (LazyLift drop-in). Заполняет
        // email_messages.category — для дальнейших шагов (linker уровня 4
        // использует это как сигнал, парсер позиций — как gate).
        // ВАЖНО: запускается ДО replyLinker, потому что 4-й уровень linker'а
        // (от from_email) опирается на category=thread_reply / client_request.
        try {
            $this->categorizer->categorize($message);
            $message->refresh();
        } catch (\Throwable $e) {
            Log::warning('MailRouter: category classifier failed (non-fatal)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Phase 1.9 (inbound-часть): прицепить письмо к существующей Request
        // через 5 уровней — In-Reply-To / References / subject-code /
        // from_email open-requests / AI multi-choice clarifier.
        // Если linked — IncomingMailProcessor::processIfRequest сам пропустит
        // создание новой Request (idempotency check на related_request_id).
        try {
            $linkedRequest = $this->replyLinker->tryLink($message);
        } catch (\Throwable $e) {
            $linkedRequest = null;
            Log::warning('MailRouter: reply linker failed (non-fatal)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Phase 1.9: если reply прицеплен к существующей Request — запустим
        // парсер с force=true для извлечения ДОПОЛНИТЕЛЬНЫХ позиций
        // («забыл указать ещё M-1234 - 3 шт»). RequestItemPersister
        // идемпотентен: дубликаты по article+name пропускает, новые
        // добавляет к существующей Request. category-гейт в persister'е
        // не блокирует thread_reply если related_request_id уже есть.
        if ($linkedRequest !== null) {
            \App\Jobs\Mail\ParseRequestItemsJob::dispatch($message->id, true);

            // Phase 4 (Foundation §7.2): inbound intent classifier.
            // Запускаем только для статусов где имеет смысл (quoted /
            // under_review / postponed / awaiting_clarification).
            if ($this->inboundClassifier->isApplicable($linkedRequest)) {
                try {
                    $intent = $this->inboundClassifier->classify($message->fresh(), $linkedRequest);
                    if ($intent !== null) {
                        $this->aiDecisions->recordSuggestion(
                            $intent['type'],
                            $linkedRequest,
                            $message,
                            (float) $intent['confidence'],
                            $intent['payload'],
                        );
                    }
                } catch (\Throwable $e) {
                    Log::warning('MailRouter: inbound intent classifier failed (non-fatal)', [
                        'email_message_id' => $message->id,
                        'request_id' => $linkedRequest->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Foundation §6.2 Phase B/C: если у Request есть pending
            // clarification batches (sent, без answered_at) — async LLM-job
            // сматчит ответ клиента с конкретными вопросами и извлечёт
            // enrichment suggestions (артикул / бренд / qty).
            try {
                $pendingBatches = \App\Models\ClarificationBatch::query()
                    ->where('request_id', $linkedRequest->id)
                    ->where('status', \App\Models\ClarificationBatch::STATUS_SENT)
                    ->whereNull('answered_at')
                    ->pluck('id');
                foreach ($pendingBatches as $batchId) {
                    \App\Jobs\Mail\MatchClarificationAnswersJob::dispatch($message->id, $batchId);
                }
            } catch (\Throwable $e) {
                Log::warning('MailRouter: dispatch clarification matcher failed (non-fatal)', [
                    'email_message_id' => $message->id,
                    'request_id' => $linkedRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $matches = $this->engine->match($message);

        // Foundation §2.3-2.4: если ни одно rule-based правило не сработало —
        // AI fallback. После AI пробуем правила ещё раз (теперь могут
        // сработать те, что match_mode=ai_classified).
        if (empty($matches)) {
            $classified = $this->classifier->classify($message);
            if ($classified !== null) {
                $message->refresh();
                $matches = $this->engine->match($message);
            }
        }

        // Phase 1.8: для писем-заявок создаём Request (даже если правил
        // не было — Foundation §2.5, request → создать Request).
        if ($message->ai_classification === \App\Enums\EmailClassification::Request->value) {
            $this->incoming->processIfRequest($message);
        }

        if (empty($matches)) {
            $this->recordNoMatch($message);

            return;
        }

        foreach ($matches as $rule) {
            $this->applyRule($rule, $message);
        }
    }

    private function applyRule(MailRoutingRule $rule, EmailMessage $message): void
    {
        $audit = new RoutedMail([
            'email_message_id' => $message->id,
            'rule_id' => $rule->id,
            'ai_classified_as' => $message->ai_classification,
            'action_taken' => $rule->action_type->value,
            'forwarded_to' => $rule->forward_to_email,
            'label_applied' => $rule->label,
            'success' => true,
            'processed_at' => now(),
        ]);

        try {
            switch ($rule->action_type) {
                case MailRuleActionType::Forward:
                    if ($rule->forward_to_email) {
                        $ok = $this->forwarder->forward($message, $rule->forward_to_email, $rule->name);
                        if (! $ok) {
                            $audit->success = false;
                            $audit->error_message = 'forward failed (см. лог)';
                        }
                    }
                    if ($rule->label) {
                        $this->labels->applyLabel($message, $rule->label);
                    }
                    break;

                case MailRuleActionType::LabelOnly:
                    if ($rule->label) {
                        $ok = $this->labels->applyLabel($message, $rule->label);
                        if (! $ok) {
                            $audit->success = false;
                            $audit->error_message = 'label apply failed (см. лог)';
                        }
                    }
                    break;

                case MailRuleActionType::TriggerRequestCreation:
                    // Phase 1.8: создание Request из IncomingMailProcessor.
                    if ($rule->label) {
                        $this->labels->applyLabel($message, $rule->label);
                    }
                    break;
            }

            $rule->increment('match_count');
        } catch (\Throwable $e) {
            $audit->success = false;
            $audit->error_message = mb_substr($e->getMessage(), 0, 1000);
            Log::error('MailRouter: rule application failed', [
                'rule_id' => $rule->id,
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        $audit->save();
    }

    private function recordNoMatch(EmailMessage $message): void
    {
        RoutedMail::create([
            'email_message_id' => $message->id,
            'rule_id' => null,
            'ai_classified_as' => $message->ai_classification,
            'action_taken' => 'none',
            'success' => true,
            'processed_at' => now(),
        ]);
    }

    private function recordLoopSkipped(EmailMessage $message): void
    {
        RoutedMail::create([
            'email_message_id' => $message->id,
            'rule_id' => null,
            'ai_classified_as' => $message->ai_classification,
            'action_taken' => 'loop_skipped',
            'success' => true,
            'processed_at' => now(),
        ]);
        Log::info('MailRouter: loop guard triggered, skipping rules', [
            'email_message_id' => $message->id,
            'subject' => mb_substr((string) $message->subject, 0, 80),
            'from' => $message->from_email,
        ]);
    }

    /**
     * Письмо — это вернувшийся к нам наш собственный forward?
     * Проверяем по X-MyLift-Forwarded заголовку и subject-префиксу.
     */
    private function isLoopMessage(EmailMessage $message): bool
    {
        $headers = (array) ($message->headers ?? []);
        // headers — jsonb; ключи могут быть в любом регистре.
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'X-MyLift-Forwarded') === 0) {
                return true;
            }
            if (strcasecmp((string) $name, 'x_mylift_forwarded') === 0) {
                return true;
            }
        }

        $subject = (string) $message->subject;
        if (str_starts_with($subject, '[MyLift forward]')) {
            return true;
        }

        return false;
    }
}
