<?php

namespace App\Livewire\MailRules;

use App\Models\MailRoutingRule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Список правил маршрутизации (Phase 1.5ж).
 * Доступ: head_of_sales / director через middleware role.
 */
class RuleList extends Component
{
    #[Computed]
    public function rules()
    {
        return MailRoutingRule::orderBy('priority')->orderBy('id')->get();
    }

    public function toggleActive(int $id): void
    {
        $rule = MailRoutingRule::find($id);
        if (! $rule) {
            return;
        }
        $rule->is_active = ! $rule->is_active;
        $rule->save();
    }

    public function delete(int $id): void
    {
        MailRoutingRule::where('id', $id)->delete();
    }

    public function render()
    {
        return view('livewire.mail-rules.rule-list');
    }
}
