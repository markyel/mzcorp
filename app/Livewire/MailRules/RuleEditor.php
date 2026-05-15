<?php

namespace App\Livewire\MailRules;

use App\Enums\MailRuleActionType;
use App\Enums\MailRuleField;
use App\Enums\MailRuleMatchMode;
use App\Enums\MailRuleOperator;
use App\Models\Mailbox;
use App\Models\MailRoutingRule;
use Livewire\Attributes\Validate;
use Livewire\Component;

class RuleEditor extends Component
{
    public ?int $ruleId = null;

    #[Validate('required|string|min:2|max:120')]
    public string $name = '';

    #[Validate('integer|min:0|max:10000')]
    public int $priority = 100;

    public bool $isActive = true;
    public bool $isTerminal = true;

    /** @var array<int> mailbox_id, [] = все */
    public array $mailboxScope = [];

    public string $matchMode = 'any_of';

    /** @var array<int, array{field: string, op: string, values: string}> */
    public array $criteria = [];

    public string $actionType = 'label_only';
    public ?string $forwardToEmail = null;
    public ?string $label = null;

    public function mount(?MailRoutingRule $rule = null): void
    {
        if ($rule && $rule->exists) {
            $this->ruleId = $rule->id;
            $this->name = $rule->name;
            $this->priority = (int) $rule->priority;
            $this->isActive = (bool) $rule->is_active;
            $this->isTerminal = (bool) $rule->is_terminal;
            $this->mailboxScope = (array) ($rule->mailbox_scope ?? []);
            $this->matchMode = $rule->match_mode->value;
            $this->actionType = $rule->action_type->value;
            $this->forwardToEmail = $rule->forward_to_email;
            $this->label = $rule->label;

            $this->criteria = array_map(fn (array $c) => [
                'field' => $c['field'] ?? 'subject',
                'op' => $c['op'] ?? 'contains_any',
                'values' => is_array($c['values'] ?? null) ? implode(', ', $c['values']) : (string) ($c['values'] ?? ''),
            ], (array) ($rule->match_criteria ?? []));
        }

        if (empty($this->criteria)) {
            $this->criteria = [
                ['field' => 'subject', 'op' => 'contains_any', 'values' => ''],
            ];
        }
    }

    public function addCriterion(): void
    {
        $this->criteria[] = ['field' => 'subject', 'op' => 'contains_any', 'values' => ''];
    }

    public function removeCriterion(int $index): void
    {
        unset($this->criteria[$index]);
        $this->criteria = array_values($this->criteria);
        if (empty($this->criteria)) {
            $this->addCriterion();
        }
    }

    public function save()
    {
        $this->validate();

        // Дополнительная валидация под action_type.
        if ($this->actionType === 'forward' && ! filter_var($this->forwardToEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addError('forwardToEmail', 'Для action=forward нужен корректный email.');

            return null;
        }
        if ($this->actionType !== 'trigger_request_creation' && empty($this->label) && $this->actionType === 'label_only') {
            $this->addError('label', 'Для action=label_only нужно указать имя метки.');

            return null;
        }

        $compiledCriteria = [];
        foreach ($this->criteria as $c) {
            $values = array_values(array_filter(
                array_map('trim', explode(',', (string) ($c['values'] ?? ''))),
                fn ($v) => $v !== '',
            ));
            if (empty($values)) {
                continue;
            }
            $compiledCriteria[] = [
                'field' => $c['field'],
                'op' => $c['op'],
                'values' => $values,
            ];
        }

        $data = [
            'name' => $this->name,
            'priority' => $this->priority,
            'is_active' => $this->isActive,
            'is_terminal' => $this->isTerminal,
            'mailbox_scope' => $this->mailboxScope ?: null,
            'match_mode' => MailRuleMatchMode::from($this->matchMode),
            'ai_match_type' => null,
            'match_criteria' => $compiledCriteria,
            'action_type' => MailRuleActionType::from($this->actionType),
            'forward_to_email' => $this->actionType === 'forward' ? $this->forwardToEmail : null,
            'label' => $this->label,
        ];

        if ($this->ruleId) {
            MailRoutingRule::where('id', $this->ruleId)->update($data);
        } else {
            $data['created_by_user_id'] = auth()->id();
            MailRoutingRule::create($data);
        }

        session()->flash('status', 'Правило сохранено.');

        return $this->redirect(route('mail-rules.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.mail-rules.rule-editor', [
            'fields' => MailRuleField::cases(),
            'operators' => MailRuleOperator::cases(),
            'modes' => MailRuleMatchMode::cases(),
            'actions' => MailRuleActionType::cases(),
            'mailboxes' => Mailbox::orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }
}
