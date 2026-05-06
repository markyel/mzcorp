<?php

namespace App\Enums;

/**
 * Что делает правило при срабатывании.
 *
 * forward                  — переслать письмо на forward_to_email и поставить label.
 * label_only               — только повесить label (никаких действий с письмом).
 * trigger_request_creation — создать Request в MyLift (Phase 1.8).
 */
enum MailRuleActionType: string
{
    case Forward = 'forward';
    case LabelOnly = 'label_only';
    case TriggerRequestCreation = 'trigger_request_creation';

    public function label(): string
    {
        return match ($this) {
            self::Forward => 'Переслать',
            self::LabelOnly => 'Только метка',
            self::TriggerRequestCreation => 'Создать заявку',
        };
    }
}
