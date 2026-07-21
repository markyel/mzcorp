<?php

namespace App\Services\HonestSign;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Заполнение файла поставки кодами «Честного знака».
 *
 * Работает по ЗАГОЛОВКАМ, а не по фиксированным буквам колонок: шапка у файлов
 * поставки съезжает (в эталоне заказчика она на 5-й строке, в файлах BUENO — на
 * 8-й). Ищем строку с «GTIN» + «КИЗ» и источником артикула, от неё и пляшем.
 *
 * Артикул (MZ-ID) строки берётся из двух возможных источников:
 *  1. явная колонка «MZ-ID» (приоритет), ЛИБО
 *  2. начало строки колонки «…ТОРГ-12» до первой запятой — там артикул зашит в
 *     наименование: «M00722, Датчик Lichtschranke…» → M00722.
 * Если в файле есть обе — используется явный MZ-ID, а ТОРГ-12 подхватывается
 * построчно только там, где ячейка MZ-ID пуста. Это делает инструмент
 * универсальным: работает и со старым форматом (колонка MZ-ID), и с BUENO
 * (артикул внутри «Наименование ТОРГ-12»).
 *
 * Трогаем ТОЛЬКО две ячейки (GTIN и КИЗ) — остальные колонки (вес, цены,
 * формулы, итоги) остаются как были, файл возвращается тем же .xlsx.
 */
class HonestSignExcelFiller
{
    /** Заголовки, по которым опознаём шапку (нормализованные, точное совпадение). */
    private const H_ARTICLE = ['mz-id', 'mzid', 'mz id'];
    private const H_GTIN = ['gtin'];
    private const H_KIZ = ['киз', 'kiz'];

    /** Колонка-наименование с зашитым артикулом — по вхождению «торг-12». */
    private const H_TORG12_MARKER = 'торг-12';

    /** Docк колонок шапки. */
    private const MAX_HEADER_SCAN_ROWS = 30;

    public function __construct(private readonly HonestSignPdfParser $parser)
    {
    }

    /**
     * @param  array<string, array{gtin: string, codes: array<int, string>}>  $byArticle  из HonestSignPdfParser::groupByArticle
     * @return array{
     *   path: string,
     *   filled: array<int, array{article: string, row: int, codes: int}>,
     *   unmatched: array<int, string>,
     *   warnings: array<int, string>
     * }
     */
    public function fill(string $sourcePath, array $byArticle, string $outputPath): array
    {
        $warnings = [];
        $filled = [];
        $unmatched = [];

        $spreadsheet = IOFactory::load($sourcePath);
        $sheet = $spreadsheet->getActiveSheet();

        $header = $this->locateHeader($sheet);
        if ($header === null) {
            throw new \DomainException(
                'В файле не найдена строка заголовков со столбцами «GTIN», «КИЗ» и '
                . '«MZ-ID» (или «Наименование ТОРГ-12» с артикулом в начале строки). '
                . 'Проверьте, что загружен правильный файл поставки.'
            );
        }

        $articleRows = $this->indexArticleRows($sheet, $header);

        foreach ($byArticle as $article => $data) {
            $key = $this->normalizeArticle($article);
            if (! isset($articleRows[$key])) {
                $unmatched[] = $article;
                continue;
            }

            $row = $articleRows[$key];
            $kiz = $this->parser->formatKizCell($data['codes']);

            // GTIN пишем строкой: ведущий ноль (04681…) в числовом формате теряется.
            $sheet->setCellValueExplicit(
                $header['gtin'] . $row,
                $data['gtin'],
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
            );
            $sheet->setCellValueExplicit(
                $header['kiz'] . $row,
                $kiz,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
            );
            // Несколько кодов — каждый с новой строки, иначе видно только первый.
            $sheet->getStyle($header['kiz'] . $row)->getAlignment()->setWrapText(true);

            $filled[] = ['article' => $article, 'row' => $row, 'codes' => count($data['codes'])];
        }

        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($outputPath);
        $spreadsheet->disconnectWorksheets();

        return [
            'path' => $outputPath,
            'filled' => $filled,
            'unmatched' => $unmatched,
            'warnings' => $warnings,
        ];
    }

    /**
     * Найти строку шапки и буквы нужных колонок.
     *
     * Шапка валидна, если есть GTIN + КИЗ И хотя бы один источник артикула:
     * явная колонка «MZ-ID» и/или колонка «…ТОРГ-12».
     *
     * @return array{row: int, article: ?string, torg12: ?string, gtin: string, kiz: string}|null
     */
    private function locateHeader(Worksheet $sheet): ?array
    {
        $maxRow = min($sheet->getHighestDataRow(), self::MAX_HEADER_SCAN_ROWS);
        $maxCol = $sheet->getHighestDataColumn();

        for ($row = 1; $row <= $maxRow; $row++) {
            $article = $torg12 = $gtin = $kiz = null;

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
                    } elseif ($gtin === null && in_array($v, self::H_GTIN, true)) {
                        $gtin = $col;
                    } elseif ($kiz === null && in_array($v, self::H_KIZ, true)) {
                        $kiz = $col;
                    }
                }
            }

            if ($gtin !== null && $kiz !== null && ($article !== null || $torg12 !== null)) {
                return ['row' => $row, 'article' => $article, 'torg12' => $torg12, 'gtin' => $gtin, 'kiz' => $kiz];
            }
        }

        return null;
    }

    /**
     * MZ-ID → номер строки (первое вхождение).
     *
     * @param  array{row: int, article: ?string, torg12: ?string, gtin: string, kiz: string}  $header
     * @return array<string, int>
     */
    private function indexArticleRows(Worksheet $sheet, array $header): array
    {
        $map = [];
        $last = $sheet->getHighestDataRow();

        for ($row = $header['row'] + 1; $row <= $last; $row++) {
            $key = $this->articleForRow($sheet, $header, $row);
            if ($key !== '' && ! isset($map[$key])) {
                $map[$key] = $row;
            }
        }

        return $map;
    }

    /**
     * Артикул строки: приоритет — явная колонка MZ-ID; если её нет или ячейка
     * пуста — из начала «…ТОРГ-12» до первой запятой («M00722, Датчик…» → M00722).
     *
     * @param  array{row: int, article: ?string, torg12: ?string, gtin: string, kiz: string}  $header
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
            $beforeComma = explode(',', $raw, 2)[0];

            return $this->normalizeArticle($beforeComma);
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
