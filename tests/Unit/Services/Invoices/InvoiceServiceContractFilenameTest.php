<?php

namespace Tests\Unit\Services\Invoices;

use App\Services\Invoices\InvoiceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit тест InvoiceService::isContractOrSpecFilename() — guard, который не
 * даёт создать Invoice из приложенных к счёту договора/спецификации.
 *
 * Регресс M-2026-1797: счёт получил номер спецификации (31) вместо номера счёта
 * (5742), потому что «Спец 31.pdf» (сокращение) не подпадал под `спецификац`
 * и доходил до создания Invoice раньше настоящего «Счет МЗ-5742 …».
 */
class InvoiceServiceContractFilenameTest extends TestCase
{
    #[DataProvider('filenameProvider')]
    public function test_is_contract_or_spec_filename(string $filename, bool $expected): void
    {
        $this->assertSame($expected, InvoiceService::isContractOrSpecFilename($filename));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function filenameProvider(): iterable
    {
        // Спецификация — полная и сокращённая (M-2026-1797).
        yield 'спец сокр. + пробел' => ['Спец 31.pdf', true];
        yield 'спец сокр. + подчёркивание' => ['Спец_31.xls', true];
        yield 'спец сокр. + точка' => ['Спец.pdf', true];
        yield 'спецификация полная' => ['Спецификация №31 НЬТОН ПЛАЗА.doc', true];
        yield 'договор' => ['Договор поставки (счет 5687).pdf', true];

        // Сам счёт — НЕ договор/спец (создаём Invoice).
        yield 'счет через е' => ['Счет МЗ-5742 от 2026-06-01_15-52-15.xls', false];
        yield 'счёт через ё' => ['Счёт на оплату 123.pdf', false];
        yield 'invoice en' => ['Invoice_5742.pdf', false];
        yield 'кп' => ['Предложение МЗ-356373.pdf', false];

        // Не ложные срабатывания на словах, начинающихся со «спец».
        yield 'специальное (не спец-токен)' => ['специальное предложение.pdf', false];
        yield 'спецодежда (не спец-токен)' => ['спецодежда.pdf', false];

        yield 'пустое имя' => ['', false];
    }
}
