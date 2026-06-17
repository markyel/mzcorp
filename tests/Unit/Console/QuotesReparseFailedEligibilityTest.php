<?php

namespace Tests\Unit\Console;

use App\Console\Commands\QuotesReparseFailedCommand as Cmd;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Границы cap/throttle у self-healing переразбора счетов
 * (QuotesReparseFailedCommand::classifyEligibility).
 *
 * Pure-функция — БД не нужна. Защищает от off-by-one в потолке попыток
 * и от слишком частого повторного дёргания одного quote при простое OpenAI.
 */
class QuotesReparseFailedEligibilityTest extends TestCase
{
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-06-17 12:00:00', config('app.timezone'));
    }

    public function test_first_attempt_with_no_prior_run_is_eligible(): void
    {
        $this->assertSame(
            Cmd::ELIGIBLE,
            Cmd::classifyEligibility(0, null, 6, 2, $this->now()),
        );
    }

    public function test_attempts_at_cap_are_capped(): void
    {
        // attempts == maxAttempts → стоп (потолок достигнут).
        $this->assertSame(
            Cmd::CAPPED,
            Cmd::classifyEligibility(6, null, 6, 2, $this->now()),
        );
    }

    public function test_attempts_above_cap_are_capped(): void
    {
        $this->assertSame(
            Cmd::CAPPED,
            Cmd::classifyEligibility(7, $this->now()->subDays(5), 6, 2, $this->now()),
        );
    }

    public function test_last_attempt_within_interval_is_throttled(): void
    {
        // Прошёл 1 час при min-interval 2ч → ещё рано.
        $lastAt = $this->now()->subHour();
        $this->assertSame(
            Cmd::THROTTLED,
            Cmd::classifyEligibility(1, $lastAt, 6, 2, $this->now()),
        );
    }

    public function test_last_attempt_past_interval_is_eligible(): void
    {
        // Прошло 3 часа при min-interval 2ч → можно повторить.
        $lastAt = $this->now()->subHours(3);
        $this->assertSame(
            Cmd::ELIGIBLE,
            Cmd::classifyEligibility(1, $lastAt, 6, 2, $this->now()),
        );
    }

    public function test_interval_boundary_exactly_elapsed_is_eligible(): void
    {
        // Ровно min-interval прошёл (lastAt + 2ч == now) → НЕ в будущем → eligible.
        $lastAt = $this->now()->subHours(2);
        $this->assertSame(
            Cmd::ELIGIBLE,
            Cmd::classifyEligibility(1, $lastAt, 6, 2, $this->now()),
        );
    }

    public function test_cap_takes_precedence_over_throttle(): void
    {
        // Даже если интервал ещё не прошёл — потолок попыток важнее.
        $lastAt = $this->now()->subMinutes(5);
        $this->assertSame(
            Cmd::CAPPED,
            Cmd::classifyEligibility(6, $lastAt, 6, 2, $this->now()),
        );
    }
}
