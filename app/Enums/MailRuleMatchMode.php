<?php

namespace App\Enums;

/**
 * Режим сопоставления критериев правила.
 *
 * any_of — правило срабатывает, если ХОТЯ БЫ ОДИН критерий совпал.
 * all_of — все критерии должны совпасть.
 *
 * Режим `ai_classified` удалён вместе со вторым уровнем AI-классификации
 * (gpt-4o-mini). Решение «создать Request» теперь принимается строго
 * по EmailCategory в MailRouter перед запуском engine.
 */
enum MailRuleMatchMode: string
{
    case AnyOf = 'any_of';
    case AllOf = 'all_of';
}
