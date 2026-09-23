<?php

namespace Tests\Unit\Services\Request;

use App\Services\Request\AssignmentService;
use PHPUnit\Framework\TestCase;

/**
 * Пропорциональная раздача: очередь считается по «додано относительно своей
 * доли», а не по числу заявок. Менеджер на четверти ставки должен получать
 * вчетверо меньше, а не столько же.
 *
 * Правило выбора вынесено в чистую функцию ниже — тот же порядок сортировки,
 * что и в сервисе (pickBySmoothShare): меньший fill первым, при равенстве —
 * больший процент, затем тот, кому давно не давали.
 */
class ProportionalAssignmentTest extends TestCase
{
    /**
     * @param  array<int, array{share: float, today: int, last: ?string}>  $managers
     */
    private function pick(array $managers): int
    {
        uasort($managers, function (array $a, array $b) {
            $fillA = $a['today'] / max($a['share'], 1e-9);
            $fillB = $b['today'] / max($b['share'], 1e-9);
            if (abs($fillA - $fillB) > 1e-9) {
                return $fillA <=> $fillB;
            }
            if (abs($a['share'] - $b['share']) > 1e-9) {
                return $b['share'] <=> $a['share'];
            }

            return strcmp((string) $a['last'], (string) $b['last']);
        });

        return (int) array_key_first($managers);
    }

    public function test_a_quarter_rate_manager_gets_a_quarter_of_the_flow(): void
    {
        // Полный менеджер уже взял четыре заявки, четвертьставочный — одну:
        // доли выбраны поровну, очередь снова у полного.
        $this->assertSame(1, $this->pick([
            1 => ['share' => 1.0, 'today' => 4, 'last' => '10:00'],
            2 => ['share' => 0.25, 'today' => 1, 'last' => '10:05'],
        ]));

        // А если четвертьставочному дали вторую — очередь всё равно у полного.
        $this->assertSame(1, $this->pick([
            1 => ['share' => 1.0, 'today' => 4, 'last' => '10:00'],
            2 => ['share' => 0.25, 'today' => 2, 'last' => '10:05'],
        ]));
    }

    public function test_at_the_start_of_the_day_the_larger_share_goes_first(): void
    {
        $this->assertSame(2, $this->pick([
            1 => ['share' => 0.5, 'today' => 0, 'last' => '09:00'],
            2 => ['share' => 1.0, 'today' => 0, 'last' => '09:00'],
        ]));
    }

    public function test_equal_shares_take_turns(): void
    {
        $this->assertSame(2, $this->pick([
            1 => ['share' => 1.0, 'today' => 3, 'last' => '11:00'],
            2 => ['share' => 1.0, 'today' => 2, 'last' => '10:00'],
        ]));
    }

    public function test_the_mode_falls_back_to_smart_on_anything_unknown(): void
    {
        $this->assertSame('smart', AssignmentService::MODE_SMART);
        $this->assertSame('пропорциональный', AssignmentService::modeLabel(AssignmentService::MODE_PROPORTIONAL));
        $this->assertSame('sticky и балансировка', AssignmentService::modeLabel('что-то ещё'));
    }
}
