<?php

namespace App\Services\Mail;

use App\Enums\MailRuleActionType;
use App\Models\EmailMessage;
use App\Models\MailRoutingRule;
use App\Models\RoutedMail;
use App\Services\Mail\Routing\RoutingContext;
use App\Services\Mail\Routing\RoutingPipeline;
use Illuminate\Support\Facades\Log;

/**
 * Маршрутизация одного письма (Foundation §1.5): упорядоченная цепочка
 * обработчиков RoutingPipeline (порядок — контракт, см. RoutingPipeline::HANDLERS)
 * → запись решения в журнал mail_decisions → правила маршрутизации
 * (forward/label) для писем, дошедших до конца цепочки.
 *
 * До 2026-09-09 всё это был один метод route() на 830 строк с 19 точками
 * выхода; логика обработчиков перенесена дословно, поведение не менялось.
 * Решение «создать Request» принимается строго по EmailCategory (gpt-4o,
 * Phase 1.8c) в CreateRequestHandler.
 */
class MailRouter
{
    public function __construct(
        private readonly MailRoutingRuleEngine $engine,
        private readonly MailLabelService $labels,
        private readonly MailForwarder $forwarder,
        private readonly RoutingPipeline $pipeline,
    ) {
    }

    public function route(EmailMessage $message): void
    {
        $ctx = new RoutingContext($message);
        $decision = $this->pipeline->run($ctx);
        if ($decision === null) {
            // Не должно случаться: CreateRequestHandler всегда решает. Страховка.
            Log::warning('MailRouter: pipeline produced no decision', ['email_message_id' => $message->id]);

            return;
        }

        app(MailDecisionRecorder::class)->record($message, $decision->stage, $decision->requestId, $decision->payload);

        if ($decision->runRules) {
            $this->applyRules($message);
        }
    }

    /**
     * Прогнать детектор исходящих документов по уже привязанному к заявке
     * исходящему письму. Логика — в OutboundDocumentDetectionService (её же
     * зовёт OutboundHandler цепочки); метод оставлен как публичная точка входа
     * для OutboundReplyHooks и quotes:detect-missed-outbound.
     */
    public function runOutboundDocumentDetection(EmailMessage $message, \App\Models\Request $request): void
    {
        app(OutboundDocumentDetectionService::class)->run($message, $request);
    }

    /** Правила маршрутизации РОПа (forward / label): первое terminal-правило обрывает цепочку. */
    private function applyRules(EmailMessage $message): void
    {
        $matches = $this->engine->match($message);

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
            'ai_classified_as' => $message->category,
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
                    // Phase 1.8: создание Request — в CreateRequestHandler по категории;
                    // здесь действие правила сводится к метке.
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
            'ai_classified_as' => $message->category,
            'action_taken' => 'none',
            'success' => true,
            'processed_at' => now(),
        ]);
    }
}
