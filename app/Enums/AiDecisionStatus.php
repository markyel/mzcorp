<?php

namespace App\Enums;

/**
 * Жизненный цикл AI-решения (ai_decisions.status).
 *
 *   suggested            — детектор сработал, UI показал prompt оператору
 *   auto_applied         — детектор сработал И тип в auto-mode → применено сразу
 *   manually_confirmed   — оператор подтвердил suggestion (положительный override)
 *   manually_overridden  — оператор выбрал ДРУГОЙ статус, не предложенный AI
 *   dismissed            — оператор закрыл prompt без действия
 *   failed               — apply transitionTo упал (DomainException)
 *
 * Для Foundation §7.3 quality score:
 *   correctness = (auto_applied + manually_confirmed) / total
 *   override_rate = manually_overridden / (auto_applied + manually_confirmed + manually_overridden)
 */
enum AiDecisionStatus: string
{
    case Suggested = 'suggested';
    case AutoApplied = 'auto_applied';
    case ManuallyConfirmed = 'manually_confirmed';
    case ManuallyOverridden = 'manually_overridden';
    case Dismissed = 'dismissed';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this !== self::Suggested;
    }

    public function isPositive(): bool
    {
        return $this === self::AutoApplied || $this === self::ManuallyConfirmed;
    }
}
