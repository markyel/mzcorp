<?php

namespace Tests\Unit\Catalog;

use App\Services\Catalog\CatalogManualSyncService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Разбор выгрузки 1С с актуализированными ценами: два вида одного файла (числа текстом
 * «2 727,00» и заголовки с пробелами — или числами без пробелов), без базы.
 */
class CatalogManualSyncParseTest extends TestCase
{
    private function file(array $header, array $rows): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([$header, ...$rows], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'sync').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    public function test_numbers_as_text_with_spaces_and_spaced_headers(): void
    {
        $path = $this->file(
            ['Веб', 'Артикул', 'Наименование', 'Цена закупки', 'Маржа%', 'Цена продажи', 'Цена продажи (мин)', 'Дата цены продажи', 'На складе <Свободно>'],
            [
                ['Да', 'M10776', 'Поручень резин. 7539FWNX', '2 727,00', 1.61, '4 399,00', '3 519,20', 46311, 0],
                ['Нет', 'm13575 ', 'Предохранитель ВП1-1В 5А', 44.25, 1.6, 70.8, 60.18, '16.10.2026', 0],
                ['Да', 'M00001', 'Без цены', '', 1.6, '', '', 46311, 0],
                ['Да', 'M10776', 'Повтор', '1,00', 1, '2,00', '1,00', 46311, 0],
            ],
        );

        $rows = app(CatalogManualSyncService::class)->parse($path);

        $this->assertCount(4, $rows);
        $this->assertSame(['M10776', 'M13575', 'M00001', 'M10776'], array_column($rows, 'sku'));
        $this->assertSame([4399.0, 3519.2, 2727.0, '2026-10-16'], [$rows[0]['price'], $rows[0]['price_min'], $rows[0]['purchase_price'], $rows[0]['price_date']]);
        $this->assertSame([70.8, 60.18, 44.25, '2026-10-16'], [$rows[1]['price'], $rows[1]['price_min'], $rows[1]['purchase_price'], $rows[1]['price_date']]);
        $this->assertSame([null, null, 'нет цены продажи'], [$rows[0]['error'], $rows[1]['error'], $rows[2]['error']]);
        $this->assertStringContainsString('повторяется', (string) $rows[3]['error']);
        $this->assertSame(2, $rows[0]['line']);
    }

    public function test_plain_numbers_and_headers_without_spaces(): void
    {
        $path = $this->file(
            ['Веб', 'Артикул', 'Наименование', 'Ценазакупки', 'Маржа%', 'Ценапродажи', 'Ценапродажи(мин)', 'Дата цены продажи'],
            [['Да', 'M04990', 'КВШ D=600 мм', 35927.81, 1.6, 57484.5, 45987.6, 46311]],
        );

        $row = app(CatalogManualSyncService::class)->parse($path)[0];

        $this->assertSame([57484.5, 45987.6, 35927.81, 1.6], [$row['price'], $row['price_min'], $row['purchase_price'], $row['margin']]);
    }

    public function test_a_file_without_prices_is_refused(): void
    {
        $path = $this->file(['Артикул', 'Наименование'], [['M04990', 'КВШ']]);

        $this->expectExceptionMessage('Цена продажи');
        app(CatalogManualSyncService::class)->parse($path);
    }

    public function test_number_and_date_cells(): void
    {
        $this->assertSame(2727.0, CatalogManualSyncService::number("2\u{00A0}727,00"));
        $this->assertSame(2727.0, CatalogManualSyncService::number('2 727'));
        $this->assertNull(CatalogManualSyncService::number('—'));
        $this->assertSame('2026-10-16', CatalogManualSyncService::date(46311));
        $this->assertNull(CatalogManualSyncService::date('вчера'));
    }
}
