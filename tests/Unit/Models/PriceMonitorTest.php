<?php

namespace Tests\Unit\Models;

use App\Models\PriceMonitor;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Границы периода и обратный отсчёт до следующего запроса цены.
 * Обе функции чистые — БД не нужна.
 */
class PriceMonitorTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int}> */
    public static function intervals(): array
    {
        return [
            'период по умолчанию проходит как есть' => [90, 90],
            'месяц' => [30, 30],
            'нижняя граница' => [7, 7],
            'верхняя граница' => [365, 365],
            'слишком часто — поджимаем до недели' => [1, 7],
            'ноль' => [0, 7],
            'отрицательный' => [-40, 7],
            'больше года — поджимаем до года' => [1000, 365],
        ];
    }

    /** @dataProvider intervals */
    public function test_clamps_interval_to_sane_bounds(int $given, int $expected): void
    {
        $this->assertSame($expected, PriceMonitor::clampInterval($given));
    }

    public function test_days_left_counts_forward(): void
    {
        Carbon::setTestNow('2026-09-17 10:00:00');
        $m = new PriceMonitor(['next_due_at' => Carbon::parse('2026-09-27 08:00:00')]);
        $this->assertSame(10, $m->daysLeft());
        Carbon::setTestNow();
    }

    public function test_days_left_is_negative_when_overdue(): void
    {
        Carbon::setTestNow('2026-09-17 10:00:00');
        $m = new PriceMonitor(['next_due_at' => Carbon::parse('2026-09-14 23:00:00')]);
        $this->assertSame(-3, $m->daysLeft());
        Carbon::setTestNow();
    }

    public function test_days_left_is_zero_on_the_due_day(): void
    {
        Carbon::setTestNow('2026-09-17 23:30:00');
        $m = new PriceMonitor(['next_due_at' => Carbon::parse('2026-09-17 01:00:00')]);
        $this->assertSame(0, $m->daysLeft());
        Carbon::setTestNow();
    }

    public function test_days_left_is_null_without_a_due_date(): void
    {
        $this->assertNull((new PriceMonitor)->daysLeft());
    }
}
