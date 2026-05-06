<?php

namespace App\Console\Commands;

use App\Enums\MailRuleActionType;
use App\Enums\MailRuleField;
use App\Enums\MailRuleMatchMode;
use App\Enums\MailRuleOperator;
use App\Models\EmailMessage;
use App\Models\MailRoutingRule;
use App\Services\Mail\MailRoutingRuleEngine;
use App\Services\Mail\MailRouter;
use Illuminate\Console\Command;

/**
 * CLI для правил маршрутизации (Phase 1.5).
 *
 *   php artisan mail:rule list              — список правил
 *   php artisan mail:rule preview {message} — какие правила сработали бы
 *   php artisan mail:rule apply {message}   — реально применить правила к письму
 *   php artisan mail:rule sample            — создать примерные правила
 *   php artisan mail:rule delete {id}       — удалить правило
 */
class MailRuleCommand extends Command
{
    protected $signature = 'mail:rule
        {action : list | preview | apply | sample | delete}
        {target? : Email message id (для preview/apply) или Rule id (для delete)}';

    protected $description = 'Управление правилами маршрутизации почты';

    public function handle(MailRoutingRuleEngine $engine, MailRouter $router): int
    {
        return match ($this->argument('action')) {
            'list' => $this->listRules(),
            'preview' => $this->previewRules($engine),
            'apply' => $this->applyRules($router),
            'sample' => $this->createSamples(),
            'delete' => $this->deleteRule(),
            default => $this->invalidAction(),
        };
    }

    private function listRules(): int
    {
        $rules = MailRoutingRule::orderBy('priority')->get();

        if ($rules->isEmpty()) {
            $this->info('Правил пока нет. Создайте через mail:rule sample или UI.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'priority', 'name', 'mode', 'action', 'label', 'forward_to', 'active', 'terminal', 'matches'],
            $rules->map(fn (MailRoutingRule $r) => [
                $r->id,
                $r->priority,
                $r->name,
                $r->match_mode->value,
                $r->action_type->value,
                $r->label ?? '—',
                $r->forward_to_email ?? '—',
                $r->is_active ? 'yes' : 'no',
                $r->is_terminal ? 'yes' : 'no',
                $r->match_count,
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function previewRules(MailRoutingRuleEngine $engine): int
    {
        $message = $this->resolveMessage();
        if (! $message) {
            return self::FAILURE;
        }

        $preview = $engine->preview($message);
        if (empty($preview)) {
            $this->info('Активных правил для этого ящика нет.');

            return self::SUCCESS;
        }

        foreach ($preview as $row) {
            /** @var MailRoutingRule $rule */
            $rule = $row['rule'];
            $matched = $row['matched'];
            $marker = $matched ? '<fg=green>MATCH</>' : '<fg=gray>skip</>';
            $this->line(sprintf(
                '  %s  #%d  prio=%d  %-20s  → %s%s',
                $marker,
                $rule->id,
                $rule->priority,
                mb_substr($rule->name, 0, 20),
                $rule->action_type->value,
                $rule->is_terminal && $matched ? ' (terminal)' : '',
            ));
            if ($matched && $rule->is_terminal) {
                break;
            }
        }

        return self::SUCCESS;
    }

    private function applyRules(MailRouter $router): int
    {
        $message = $this->resolveMessage();
        if (! $message) {
            return self::FAILURE;
        }

        if (! $this->confirm("Применить правила к письму #{$message->id} «{$message->subject}» от {$message->from_email}?")) {
            return self::SUCCESS;
        }

        $router->route($message);

        $this->info('Done. Audit-записи в routed_mails:');
        foreach ($message->routedMails()->latest('id')->limit(5)->get() as $rm) {
            $this->line(sprintf(
                '  #%d  rule_id=%s  action=%s  forwarded_to=%s  label=%s  ok=%s',
                $rm->id,
                $rm->rule_id ?? '—',
                $rm->action_taken,
                $rm->forwarded_to ?? '—',
                $rm->label_applied ?? '—',
                $rm->success ? 'yes' : 'NO: ' . ($rm->error_message ?? ''),
            ));
        }

        return self::SUCCESS;
    }

    private function createSamples(): int
    {
        // Примерные правила из Foundation §1.5.
        $samples = [
            [
                'name' => 'Рекламации (subject)',
                'priority' => 10,
                'match_mode' => MailRuleMatchMode::AnyOf,
                'match_criteria' => [
                    [
                        'field' => MailRuleField::Subject->value,
                        'op' => MailRuleOperator::ContainsAny->value,
                        'values' => ['рекламация', 'претензия', 'брак', 'возврат'],
                    ],
                ],
                'action_type' => MailRuleActionType::Forward,
                'forward_to_email' => 'claims@myzip.ru',
                'label' => 'MyLift/Рекламации',
                'is_terminal' => true,
            ],
            [
                'name' => 'Бухгалтерия',
                'priority' => 20,
                'match_mode' => MailRuleMatchMode::AnyOf,
                'match_criteria' => [
                    [
                        'field' => MailRuleField::Subject->value,
                        'op' => MailRuleOperator::ContainsAny->value,
                        'values' => ['акт сверки', 'счёт-фактура', 'счет-фактура', 'оплата', 'баланс', 'УПД'],
                    ],
                ],
                'action_type' => MailRuleActionType::Forward,
                'forward_to_email' => 'buh@myzip.ru',
                'label' => 'MyLift/Бухгалтерия',
                'is_terminal' => true,
            ],
            [
                'name' => 'Не разобрано (catch-all)',
                'priority' => 9999,
                'match_mode' => MailRuleMatchMode::AnyOf,
                'match_criteria' => [
                    [
                        'field' => MailRuleField::Subject->value,
                        'op' => MailRuleOperator::ContainsAny->value,
                        'values' => [''], // никогда не сработает (защита от accidental fallback)
                    ],
                ],
                'action_type' => MailRuleActionType::LabelOnly,
                'forward_to_email' => null,
                'label' => 'MyLift/Не разобрано',
                'is_terminal' => true,
                'is_active' => false,
            ],
        ];

        foreach ($samples as $row) {
            $rule = MailRoutingRule::firstOrCreate(
                ['name' => $row['name']],
                array_merge([
                    'is_active' => true,
                ], $row),
            );
            $this->line(($rule->wasRecentlyCreated ? '+ ' : '= ') . $rule->name . ' (id=' . $rule->id . ')');
        }

        return self::SUCCESS;
    }

    private function deleteRule(): int
    {
        $id = (int) $this->argument('target');
        $rule = MailRoutingRule::find($id);
        if (! $rule) {
            $this->error('Rule not found.');

            return self::FAILURE;
        }
        if (! $this->confirm("Удалить правило #{$rule->id} «{$rule->name}»?")) {
            return self::SUCCESS;
        }
        $rule->delete();
        $this->info('Deleted.');

        return self::SUCCESS;
    }

    private function resolveMessage(): ?EmailMessage
    {
        $id = (int) $this->argument('target');
        if (! $id) {
            $this->error('Укажите id письма (target).');

            return null;
        }
        $msg = EmailMessage::find($id);
        if (! $msg) {
            $this->error('Email message not found.');

            return null;
        }

        return $msg;
    }

    private function invalidAction(): int
    {
        $this->error('Action must be: list | preview | apply | sample | delete.');

        return self::INVALID;
    }
}
