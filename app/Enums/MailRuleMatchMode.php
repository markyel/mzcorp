<?php

namespace App\Enums;

/**
 * Режим сопоставления критериев правила.
 *
 * any_of        — правило срабатывает, если ХОТЯ БЫ ОДИН критерий совпал.
 * all_of        — все критерии должны совпасть.
 * ai_classified — критерии игнорируются; правило сработает, если AI отнёс
 *                 письмо к указанному классу (см. ai_match_type).
 */
enum MailRuleMatchMode: string
{
    case AnyOf = 'any_of';
    case AllOf = 'all_of';
    case AiClassified = 'ai_classified';
}
