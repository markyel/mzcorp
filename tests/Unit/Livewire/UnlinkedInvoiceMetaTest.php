<?php

namespace Tests\Unit\Livewire;

use App\Livewire\Invoices\Unlinked;
use Tests\TestCase;

/**
 * Извлечение номера + даты счёта из имени файла
 * (Unlinked::parseInvoiceMeta) — самое багоопасное место триаж-страницы.
 * Pure-функция, БД не нужна.
 */
class UnlinkedInvoiceMetaTest extends TestCase
{
    public function test_parses_number_and_date_from_standard_invoice_filename(): void
    {
        [$number, $date] = Unlinked::parseInvoiceMeta('Счет МЗ-6197 от 2026-06-15_14-29-16.pdf');
        $this->assertSame('6197', $number);
        $this->assertNotNull($date);
        $this->assertSame('2026-06-15', $date->format('Y-m-d'));
    }

    public function test_parses_yo_variant_spelling(): void
    {
        [$number, $date] = Unlinked::parseInvoiceMeta('Счёт МЗ-5748 от 2026-06-01_16-20-08.pdf');
        $this->assertSame('5748', $number);
        $this->assertSame('2026-06-01', $date->format('Y-m-d'));
    }

    public function test_returns_null_date_when_absent(): void
    {
        [$number, $date] = Unlinked::parseInvoiceMeta('Счет МЗ-6201.pdf');
        $this->assertSame('6201', $number);
        $this->assertNull($date);
    }

    public function test_falls_back_to_bare_digit_run_without_mz_prefix(): void
    {
        [$number, $date] = Unlinked::parseInvoiceMeta('Инвойс 12345 от 2026-05-20.pdf');
        $this->assertSame('12345', $number);
        $this->assertSame('2026-05-20', $date->format('Y-m-d'));
    }

    public function test_number_null_when_unparseable(): void
    {
        [$number, $date] = Unlinked::parseInvoiceMeta('Счет без номера.pdf');
        $this->assertNull($number);
        $this->assertNull($date);
    }
}
