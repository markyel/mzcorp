<?php

namespace App\Services\HonestSign;

use App\Models\CatalogItem;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * «Честный знак» → инструмент «Названия по каталогу».
 *
 * Вход: xlsx со столбцом M-артикула (MZ-ID). Выход: тот же файл с ДОПОЛНИТЕЛЬНОЙ
 * колонкой сразу справа от артикула — русское наименование товара из каталога
 * (`catalog_items.name` по `sku`). Остальные колонки/формулы не трогаются.
 *
 * Опознание столбца артикула — по ЗАГОЛОВКУ (шапка съезжает у разных поставок):
 *  1. явная колонка «MZ-ID» (приоритет), ЛИБО
 *  2. «Наименование ТОРГ-12» с артикулом в начале строки до первой запятой
 *     («M00722, Датчик…» → M00722).
 * Тот же рецепт, что в HonestSignExcelFiller (см. его для мотивации).
 */
class HonestSignCatalogNameFiller
{
    /** Заголовки столбца артикула (нормализованные, точное совпадение). */
    private const H_ARTICLE = ['mz-id', 'mzid', 'mz id', 'mz-артикул', 'm-артикул'];

    /** Колонка-наименование с зашитым артикулом — по вхождению «торг-12». */
    private const H_TORG12_MARKER = 'торг-12';

    private const MAX_HEADER_SCAN_ROWS = 30;

    private const NEW_HEADER = 'Наименование (из каталога)';

    /**
     * @return array{path: string, matched: int, unmatched: array<int, string>, total_rows: int, new_column: string}
     */
    public function fill(string $sourcePath, string $outputPath): array
    {
        $spreadsheet = IOFactory::load($sourcePath);
        $sheet = $spreadsheet->getActiveSheet();

        $header = $this->locateHeader($sheet);
        if ($header === null) {
            throw new \DomainException(
                'В файле не найден столбец с M-артикулом («MZ-ID» или «Наименование ТОРГ-12» '
                . 'с артикулом в начале строки). Проверьте, что загружен правильный файл.'
            );
        }

        // Читаем ВСЕ артикулы построчно ДО вставки колонки — иначе буквы столбцов
        // (в т.ч. torg12) сдвинутся и articleForRow прочитает не то.
        $last = $sheet->getHighestDataRow();
        $rowArticles = [];   // row => normalized article
        $skus = [];
        for ($row = $header['row'] + 1; $row <= $last; $row++) {
            $art = $this->articleForRow($sheet, $header, $row);
            if ($art !== '') {
                $rowArticles[$row] = $art;
                $skus[$art] = true;
            }
        }

        $names = $this->lookupNames(array_keys($skus));

        // Новая колонка — сразу справа от столбца с артикулом.
        $articleCol = $header['article'] ?? $header['torg12'];
        $newCol = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($articleCol) + 1);
        $sheet->insertNewColumnBefore($newCol, 1);

        $sheet->setCellValue($newCol . $header['row'], self::NEW_HEADER);
        $sheet->getStyle($newCol . $header['row'])->getFont()->setBold(true);
        $sheet->getColumnDimension($newCol)->setWidth(45);

        $matched = 0;
        $unmatched = [];
        foreach ($rowArticles as $row => $art) {
            $name = $names[$art] ?? null;
            if ($name !== null && $name !== '') {
                $sheet->setCellValueExplicit($newCol . $row, $name, DataType::TYPE_STRING);
                $matched++;
            } else {
                $unmatched[$art] = true;
            }
        }

        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($outputPath);
        $spreadsheet->disconnectWorksheets();

        return [
            'path' => $outputPath,
            'matched' => $matched,
            'unmatched' => array_keys($unmatched),
            'total_rows' => count($rowArticles),
            'new_column' => $newCol,
        ];
    }

    /**
     * SKU → русское название. Один запрос. При дубле SKU приоритет активной записи.
     *
     * @param  array<int, string>  $skus  нормализованные (uppercase)
     * @return array<string, string>
     */
    private function lookupNames(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        $map = [];
        CatalogItem::query()
            ->whereIn('sku', $skus)
            ->orderByDesc('is_active')
            ->get(['sku', 'name', 'is_active'])
            ->each(function (CatalogItem $c) use (&$map) {
                $key = mb_strtoupper(trim((string) $c->sku));
                if ($key !== '' && ! isset($map[$key])) {
                    $map[$key] = (string) $c->name;
                }
            });

        return $map;
    }

    /**
     * Найти строку шапки и столбец артикула (MZ-ID или ТОРГ-12).
     *
     * @return array{row: int, article: ?string, torg12: ?string}|null
     */
    private function locateHeader(Worksheet $sheet): ?array
    {
        $maxRow = min($sheet->getHighestDataRow(), self::MAX_HEADER_SCAN_ROWS);
        $maxCol = $sheet->getHighestDataColumn();

        for ($row = 1; $row <= $maxRow; $row++) {
            $article = $torg12 = null;

            foreach ($sheet->getRowIterator($row, $row) as $rowIt) {
                $cells = $rowIt->getCellIterator('A', $maxCol);
                $cells->setIterateOnlyExistingCells(true);
                foreach ($cells as $cell) {
                    $v = $this->normalizeHeader((string) $cell->getValue());
                    if ($v === '') {
                        continue;
                    }
                    $col = $cell->getColumn();
                    if ($article === null && in_array($v, self::H_ARTICLE, true)) {
                        $article = $col;
                    } elseif ($torg12 === null && str_contains($v, self::H_TORG12_MARKER)) {
                        $torg12 = $col;
                    }
                }
            }

            if ($article !== null || $torg12 !== null) {
                return ['row' => $row, 'article' => $article, 'torg12' => $torg12];
            }
        }

        return null;
    }

    /**
     * Артикул строки: приоритет — явная колонка MZ-ID; иначе — из начала «…ТОРГ-12»
     * до первой запятой.
     *
     * @param  array{row: int, article: ?string, torg12: ?string}  $header
     */
    private function articleForRow(Worksheet $sheet, array $header, int $row): string
    {
        if ($header['article'] !== null) {
            $key = $this->normalizeArticle((string) $sheet->getCell($header['article'] . $row)->getValue());
            if ($key !== '') {
                return $key;
            }
        }
        if ($header['torg12'] !== null) {
            $raw = (string) $sheet->getCell($header['torg12'] . $row)->getValue();

            return $this->normalizeArticle(explode(',', $raw, 2)[0]);
        }

        return '';
    }

    private function normalizeHeader(string $v): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v) ?? ''));
    }

    private function normalizeArticle(string $v): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', trim($v)) ?? '');
    }
}
