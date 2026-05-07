<?php

namespace App\Services\Mail;

use App\Enums\MailDirection;
use App\Enums\MailRuleActionType;
use App\Models\EmailMessage;
use App\Models\MailRoutingRule;
use App\Models\RoutedMail;
use Illuminate\Support\Facades\Log;

/**
 * Применение правил маршрутизации к одному письму:
 * engine.match → действия (label, forward) → запись в routed_mails.
 *
 * Foundation §1.5 pipeline. Не выполняется для outbound (Sent) писем —
 * там у нас только tracking исходящих, а не маршрутизация.
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
    ) {
    }

    public function route(EmailMessage $message): void
    {
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

        // Phase 1.8c: расширенная категоризация (LazyLift drop-in).
        // Заполняет email_messages.category — следим за качеством классификации
        // в БД. На текущее поведение (rules + IncomingMailProcessor) НЕ влияет;
        // переключение триггера Request-creation на category — следующий шаг.
        try {
            $this->categorizer->categorize($message);
        } catch (\Throwable $e) {
            Log::warning('MailRouter: category classifier failed (non-fatal)', [
                'email_message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
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
