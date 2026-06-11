<?php

namespace Tests\Unit\Services\Invoices;

use App\Services\Calendar\RussianWorkingDayService;
use App\Services\Invoices\InvoiceService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Расчёт срока действия счёта InvoiceService::computeExpiry().
 *
 * Регресс M-2026-3307: счёт с резервом «до 16.06» получал expires_at = 11.06
 * (дата документа + 5 рабочих дней), потому что реальный срок резерва из
 * документа/письма не учитывался. Теперь явная дата valid_until приоритетнее
 * дефолтного +5; fallback сохраняется, если даты нет.
 *
 * Расширяет Laravel TestCase (а не голый PHPUnit) — computeExpiry читает
 * config('app.timezone'), а календарь — config('russian_calendar'). БД не нужна.
 */
class InvoiceServiceExpiryTest extends TestCase
{
    private function calendar(): RussianWorkingDayService
    {
        return new RussianWorkingDayService();
    }

    private function day(string $date): CarbonImmutable
    {
        // В таймзоне приложения — как приходит из date-cast'а модели.
        return CarbonImmutable::parse($date, config('app.timezone'))->startOfDay();
    }

    public function test_uses_explicit_valid_until_when_present(): void
    {
        $issued = $this->day('2026-06-08');
        $validUntil = $this->day('2026-06-16');

        [$expires, $days] = InvoiceService::computeExpiry($this->calendar(), $issued, $validUntil, 5);

        $this->assertSame('2026-06-16', $expires->toDateString());
        $this->assertSame(23, $expires->hour, 'expires_at должен быть концом дня');
        $this->assertSame(59, $expires->minute);
        $this->assertGreaterThan(0, $days, 'validity_days считается как рабочие дни до даты');
    }

    public function test_falls_back_to_default_business_days_when_no_valid_until(): void
    {
        $issued = $this->day('2026-06-08');

        [$expires, $days] = InvoiceService::computeExpiry($this->calendar(), $issued, null, 5);

        $this->assertSame(5, $days);
        // Сверяемся с тем же календарём, чтобы не зависеть от списка праздников.
        $expected = $this->calendar()->addBusinessDays($issued, 5)->toDateString();
        $this->assertSame($expected, $expires->toDateString());
    }

    public function test_ignores_valid_until_earlier_than_issued(): void
    {
        $issued = $this->day('2026-06-08');
        $garbage = $this->day('2026-06-01'); // раньше даты выставления — мусор

        [$expires, $days] = InvoiceService::computeExpiry($this->calendar(), $issued, $garbage, 5);

        $this->assertSame(5, $days, 'при некорректной дате — fallback на дефолт');
        $expected = $this->calendar()->addBusinessDays($issued, 5)->toDateString();
        $this->assertSame($expected, $expires->toDateString());
    }

    public function test_valid_until_equal_to_issued_is_accepted(): void
    {
        $issued = $this->day('2026-06-08');

        [$expires, $days] = InvoiceService::computeExpiry($this->calendar(), $issued, $issued, 5);

        $this->assertSame('2026-06-08', $expires->toDateString());
        $this->assertSame(0, $days, 'тот же день — ноль рабочих дней');
    }
}
