<?php

namespace App\Services\Mail;

use App\Enums\MailRuleField;
use App\Enums\MailRuleMatchMode;
use App\Enums\MailRuleOperator;
use App\Models\EmailMessage;
use App\Models\MailRoutingRule;
use Illuminate\Support\Collection;

/**
 * Движок матчинга правил маршрутизации.
 *
 * Foundation §1.5: проходит правила в порядке priority (asc), останавливается
 * на первом совпавшем с is_terminal=true. Возвращает список совпавших правил
 * (с учётом non-terminal — может быть несколько подряд).
 *
 * Этот сервис ТОЛЬКО матчит — действия (forward/label) делает MailRouter,
 * который вызывает LabelService и Forwarder.
 *
 * AI-классификация (match_mode=ai_classified) активируется в Phase 1.6,
 * когда EmailMessage.ai_classification будет заполняться. На Phase 1.5
 * правила с match_mode=ai_classified просто никогда не сработают, пока
 * ai_classification = null.
 */
class MailRoutingRuleEngine
{
    /**
     * Применяет правила к письму. Не выполняет действий, только возвращает список матчей.
     *
     * @return array<int, MailRoutingRule>
     */
    public function match(EmailMessage $message): array
    {
        $rules = $this->loadActiveRulesForMailbox($message->mailbox_id);

        $matches = [];
        foreach ($rules as $rule) {
            if (! $this->ruleMatches($rule, $message)) {
                continue;
            }

            $matches[] = $rule;

            if ($rule->is_terminal) {
                break;
            }
        }

        return $matches;
    }

    /**
     * Превью: какие правила сработали бы на данном письме.
     * Используется в UI «Превью на реальном письме» (Phase 1.5ж).
     *
     * @return array<int, array{rule: MailRoutingRule, matched: bool}>
     */
    public function preview(EmailMessage $message): array
    {
        $rules = $this->loadActiveRulesForMailbox($message->mailbox_id);

        return $rules->map(fn (MailRoutingRule $rule) => [
            'rule' => $rule,
            'matched' => $this->ruleMatches($rule, $message),
        ])->all();
    }

    private function loadActiveRulesForMailbox(int $mailboxId): Collection
    {
        return MailRoutingRule::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (MailRoutingRule $r) => $r->appliesToMailbox($mailboxId))
            ->values();
    }

    private function ruleMatches(MailRoutingRule $rule, EmailMessage $message): bool
    {
        if ($rule->match_mode === MailRuleMatchMode::AiClassified) {
            return $rule->ai_match_type !== null
                && $message->ai_classification === $rule->ai_match_type;
        }

        $criteria = $rule->match_criteria ?? [];
        if (empty($criteria)) {
            // Пустой набор критериев на rule-based режиме — считаем НЕ совпало.
            // Это защита от случайной активации правила без условий.
            return false;
        }

        $results = array_map(
            fn (array $c) => $this->criterionMatches($c, $message),
            $criteria,
        );

        return match ($rule->match_mode) {
            MailRuleMatchMode::AnyOf => in_array(true, $results, true),
            MailRuleMatchMode::AllOf => ! in_array(false, $results, true),
            default => false,
        };
    }

    /**
     * @param  array{field: string, op: string, values?: array<int, string>}  $criterion
     */
    private function criterionMatches(array $criterion, EmailMessage $message): bool
    {
        $field = MailRuleField::tryFrom($criterion['field'] ?? '');
        $op = MailRuleOperator::tryFrom($criterion['op'] ?? '');
        $values = array_map('strval', $criterion['values'] ?? []);

        if ($field === null || $op === null || empty($values)) {
            return false;
        }

        $haystack = $this->extractField($field, $message);

        return match ($op) {
            MailRuleOperator::ContainsAny => $this->containsAny($haystack, $values),
            MailRuleOperator::NotContains => ! $this->containsAny($haystack, $values),
            MailRuleOperator::EqualsAny => $this->equalsAny($haystack, $values),
            MailRuleOperator::EndsWith => $this->endsWithAny($haystack, $values),
            MailRuleOperator::RegexMatch => $this->regexMatch($haystack, $values[0]),
        };
    }

    private function extractField(MailRuleField $field, EmailMessage $message): string
    {
        return match ($field) {
            MailRuleField::Subject => (string) $message->subject,
            MailRuleField::FromEmail => (string) $message->from_email,
            MailRuleField::FromDomain => $this->extractDomain((string) $message->from_email),
            MailRuleField::Body => trim(
                ($message->body_plain ?: '')
                . "\n"
                . strip_tags((string) $message->body_html)
            ),
        };
    }

    private function extractDomain(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '' : strtolower(substr($email, $at + 1));
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        $h = mb_strtolower($haystack);
        foreach ($needles as $n) {
            if ($n === '') {
                continue;
            }
            if (str_contains($h, mb_strtolower($n))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function equalsAny(string $haystack, array $values): bool
    {
        $h = mb_strtolower(trim($haystack));
        foreach ($values as $v) {
            if (mb_strtolower(trim($v)) === $h) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function endsWithAny(string $haystack, array $values): bool
    {
        $h = mb_strtolower($haystack);
        foreach ($values as $v) {
            if ($v === '') {
                continue;
            }
            if (str_ends_with($h, mb_strtolower($v))) {
                return true;
            }
        }

        return false;
    }

    private function regexMatch(string $haystack, string $pattern): bool
    {
        // Защита от runtime PHP warnings при кривом regex.
        $result = @preg_match($pattern, $haystack);

        return $result === 1;
    }
}
