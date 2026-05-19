<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use App\Models\RequestItem;
use Illuminate\Support\Collection;

/**
 * Структурирует данные для compare-таблицы ItemCatalogLinkDialog:
 *
 *   compare(subject, candidates, similarityMeta) -> [
 *     'candidates' => [
 *       ['catalog' => CatalogItem, 'score' => 0.94|null, 'source' => 'name+brand', ...],
 *     ],
 *     'sections' => [
 *       ['title' => 'Идентификация', 'rows' => [
 *         [
 *           'label' => 'Бренд',
 *           'sublabel' => null,
 *           'subject' => ['value' => '...', 'status' => 'req|empty', 'sub' => null],
 *           'cells' => [
 *             ['value' => '...', 'status' => 'match|diff|bad|empty', 'sub' => '...'],
 *             ...
 *           ],
 *           'allMatch' => bool,  // для "только различия" filter
 *         ],
 *       ]],
 *     ],
 *   ]
 *
 * Статусы ячеек:
 *   - req      синяя плашка subject-значения
 *   - match    зелёная (значение совпало с subject)
 *   - diff     амбер (отличается с пояснением sub)
 *   - bad      красный (критичное несоответствие — нет в наличии)
 *   - empty    серый italic ("— не указан")
 *   - plain    нейтральный (информация без сравнения, напр. цена)
 */
class CatalogComparisonService
{
    private const DIM_TOLERANCE_MM = 5;

    /**
     * @param  Collection<int, CatalogItem>  $candidates
     * @param  array<int, array{score: float, method: string, code: ?float, trgm: ?float, vector: ?float}>  $similarityMeta
     *         key = catalog_id
     */
    public function compare(RequestItem $subject, Collection $candidates, array $similarityMeta = []): array
    {
        $subjExtracted = is_array($subject->quality_assessment_payload['extracted_parameters'] ?? null)
            ? $subject->quality_assessment_payload['extracted_parameters']
            : [];
        $subjDims = $this->extractDimensions(
            trim(($subject->parsed_name ?? '') . ' ' . ($subject->parsed_article ?? ''))
        );
        $subjQty = (int) round((float) ($subject->parsed_qty ?? 0));

        $candidatesMeta = $candidates->map(function (CatalogItem $c) use ($similarityMeta) {
            $m = $similarityMeta[$c->id] ?? null;
            return [
                'catalog' => $c,
                'score' => $m['score'] ?? null,
                'method' => $m['method'] ?? null,
                'sourceLine' => $this->formatSource($m),
            ];
        })->all();

        $sections = [
            $this->sectionIdentification($subject, $candidates, $subjExtracted),
            $this->sectionDimensions($subject, $candidates, $subjDims),
            $this->sectionBusiness($subject, $candidates, $subjQty),
            $this->sectionKbAndSource($subject, $candidates, $subjExtracted, $candidatesMeta),
        ];

        return [
            'candidates' => $candidatesMeta,
            'sections' => $sections,
            'subjectQty' => $subjQty,
        ];
    }

    // ---------- Sections ----------

    private function sectionIdentification(RequestItem $subject, Collection $candidates, array $extracted): array
    {
        $rows = [];

        $subjBrand = $subject->brand?->name ?: $subject->parsed_brand;
        $rows[] = $this->row(
            'Бренд', null,
            $this->reqCell($subjBrand),
            $candidates->map(fn (CatalogItem $c) => $this->brandCell($c->brand, $subjBrand))->all(),
        );

        $rows[] = $this->row(
            'Серия / семья',
            'из текста / артикула',
            $this->reqCell($this->extractSeries($subject->parsed_name) ?? $this->extractSeries($subject->parsed_article)),
            $candidates->map(fn (CatalogItem $c) => $this->seriesCell(
                $this->extractSeries($c->name) ?? $this->extractSeries($c->brand_article),
                $this->extractSeries($subject->parsed_name) ?? $this->extractSeries($subject->parsed_article),
            ))->all(),
        );

        $subjUnit = $subject->kbCategory?->name;
        $rows[] = $this->row(
            'Узел', 'где стоит',
            $this->reqCell($subjUnit),
            $candidates->map(fn (CatalogItem $c) => $this->substringCell(
                $c->unit_name,
                $subjUnit,
                'другой компонент',
            ))->all(),
        );

        $rows[] = $this->row(
            'Артикул', null,
            $this->reqCell($subject->parsed_article, mono: true),
            $candidates->map(fn (CatalogItem $c) => $this->plainCell($c->brand_article, mono: true))->all(),
        );

        return ['title' => 'Идентификация', 'rows' => $rows];
    }

    private function sectionDimensions(RequestItem $subject, Collection $candidates, array $subjDims): array
    {
        $rows = [];

        // Длина: longest catalog size_a..f, сравниваем с subjDims.
        $subjLenStr = ! empty($subjDims) ? max($subjDims) . ' мм' : null;
        $rows[] = $this->row(
            'Длина', null,
            $this->reqCell($subjLenStr, empty: $subjLenStr === null),
            $candidates->map(function (CatalogItem $c) use ($subjDims) {
                $sizes = array_filter([$c->size_a, $c->size_b, $c->size_c, $c->size_d, $c->size_e, $c->size_f], fn ($v) => $v !== null);
                if ($sizes === []) {
                    return $this->emptyCell();
                }
                $catLen = (int) round((float) max(array_map(fn ($v) => (float) $v, $sizes)));
                $value = number_format($catLen, 0, '.', ' ') . ' мм';
                if (empty($subjDims)) {
                    return $this->plainCell($value);
                }
                foreach ($subjDims as $d) {
                    if (abs($catLen - $d) <= self::DIM_TOLERANCE_MM) {
                        return $this->matchCell($value);
                    }
                }
                $diff = $catLen - max($subjDims);
                $sub = $diff > 0 ? "длиннее на {$diff} мм" : "короче на " . abs($diff) . " мм";
                return $this->diffCell($value, $sub);
            })->all(),
        );

        // Дополнительные размеры (если есть несколько axes).
        $rows[] = $this->row(
            'Размеры (все)', 'size_a..f каталога',
            $this->reqCell(! empty($subjDims) ? implode(' × ', $subjDims) . ' мм' : null, empty: empty($subjDims), mono: true),
            $candidates->map(function (CatalogItem $c) {
                $sizes = array_filter([$c->size_a, $c->size_b, $c->size_c, $c->size_d, $c->size_e, $c->size_f], fn ($v) => $v !== null);
                if ($sizes === []) {
                    return $this->emptyCell();
                }
                $vals = array_map(fn ($v) => rtrim(rtrim((string) $v, '0'), '.'), $sizes);
                return $this->plainCell(implode(' × ', $vals) . ' мм', mono: true);
            })->all(),
        );

        // Форм-фактор.
        $rows[] = $this->row(
            'Форм-фактор', null,
            $this->reqCell(null, empty: true),
            $candidates->map(fn (CatalogItem $c) => $this->plainCell($c->form_factor))->all(),
        );

        // Вес.
        $rows[] = $this->row(
            'Вес', null,
            $this->reqCell(null, empty: true),
            $candidates->map(function (CatalogItem $c) {
                if ($c->weight === null) {
                    return $this->emptyCell();
                }
                return $this->plainCell(rtrim(rtrim((string) $c->weight, '0'), '.') . ' кг');
            })->all(),
        );

        return ['title' => 'Размеры и геометрия', 'rows' => $rows];
    }

    private function sectionBusiness(RequestItem $subject, Collection $candidates, int $subjQty): array
    {
        $rows = [];

        $rows[] = $this->row(
            'Цена за шт.', null,
            $this->reqCell(null, empty: true, sub: 'бюджет не указан'),
            $candidates->map(function (CatalogItem $c) {
                if ($c->price === null) {
                    return $this->emptyCell();
                }
                return $this->plainCell(number_format((float) $c->price, 2, '.', ' ') . ' ₽', mono: true, bold: true);
            })->all(),
        );

        if ($subjQty > 0) {
            $rows[] = $this->row(
                "Сумма на {$subjQty} шт.", null,
                $this->reqCell(null, empty: true, sub: 'после привязки'),
                $candidates->map(function (CatalogItem $c) use ($subjQty) {
                    if ($c->price === null) {
                        return $this->emptyCell();
                    }
                    $total = (float) $c->price * $subjQty;
                    return $this->plainCell(number_format($total, 2, '.', ' ') . ' ₽', mono: true, bold: true);
                })->all(),
            );
        }

        $rows[] = $this->row(
            'Наличие', null,
            $this->reqCell($subjQty > 0 ? "нужно {$subjQty} шт." : null, empty: $subjQty === 0),
            $candidates->map(function (CatalogItem $c) use ($subjQty) {
                if ($c->stock_available === null) {
                    return $this->emptyCell();
                }
                if ($c->stock_available <= 0) {
                    $sub = $c->lead_time_days ? "под заказ {$c->lead_time_days} дн" : null;
                    return $this->badCell('нет на складе', $sub);
                }
                $value = "{$c->stock_available} шт";
                if ($subjQty > 0 && $c->stock_available < $subjQty) {
                    $missing = $subjQty - $c->stock_available;
                    $sub = $c->lead_time_days ? "нужно ещё {$missing} — под заказ {$c->lead_time_days} дн" : "нужно ещё {$missing}";
                    return $this->diffCell($value, $sub);
                }
                if ($subjQty > 0 && $c->stock_available > $subjQty) {
                    return $this->matchCell($value, 'с запасом');
                }
                return $this->matchCell($value, $subjQty > 0 ? 'ровно' : null);
            })->all(),
        );

        $rows[] = $this->row(
            'Срок поставки', null,
            $this->reqCell(null, empty: true),
            $candidates->map(function (CatalogItem $c) use ($subjQty) {
                if ($c->stock_available !== null && $c->stock_available >= $subjQty && $subjQty > 0) {
                    return $this->matchCell("сегодня все {$subjQty}");
                }
                if ($c->lead_time_days !== null) {
                    return $this->plainCell("{$c->lead_time_days} дн");
                }
                return $this->emptyCell();
            })->all(),
        );

        return ['title' => 'Цена и наличие', 'rows' => $rows];
    }

    private function sectionKbAndSource(
        RequestItem $subject,
        Collection $candidates,
        array $extracted,
        array $candidatesMeta,
    ): array {
        $rows = [];

        // KB-параметры subject'а (catalog их структурно не хранит → пусто справа,
        // кроме случаев когда регекс по name даёт точное совпадение).
        foreach ($extracted as $slug => $value) {
            $rows[] = $this->row(
                $slug, 'KB-параметр',
                $this->reqCell(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)),
                $candidates->map(function (CatalogItem $c) use ($value) {
                    if (! is_scalar($value)) {
                        return $this->emptyCell('— не хранится');
                    }
                    $haystack = mb_strtolower(($c->name ?? '') . ' ' . ($c->brand_article ?? ''));
                    if (mb_strpos($haystack, mb_strtolower((string) $value)) !== false) {
                        return $this->matchCell((string) $value, 'найдено в названии');
                    }
                    return $this->emptyCell('— не хранится');
                })->all(),
            );
        }

        // Источник совпадения (метод + score из vector-search meta).
        $rows[] = $this->row(
            'Источник совпадения', null,
            $this->reqCell('эталон', empty: true),
            collect($candidatesMeta)->map(function ($meta) {
                if ($meta['sourceLine'] === null) {
                    return $this->emptyCell('— text-match');
                }
                return $this->plainCell($meta['sourceLine'], mono: true, small: true);
            })->all(),
        );

        return ['title' => 'KB-параметры и источник совпадения', 'rows' => $rows];
    }

    // ---------- Cell helpers ----------

    private function row(string $label, ?string $sublabel, array $subject, array $cells): array
    {
        $allMatch = collect($cells)->every(fn ($c) => in_array($c['status'], ['match', 'plain', 'empty'], true));
        return [
            'label' => $label,
            'sublabel' => $sublabel,
            'subject' => $subject,
            'cells' => $cells,
            'allMatch' => $allMatch,
        ];
    }

    private function reqCell(?string $value, bool $empty = false, ?string $sub = null, bool $mono = false): array
    {
        $isEmpty = $empty || $value === null || $value === '';
        return [
            'value' => $isEmpty ? ($value ?? '— ' . ($sub ?? 'не указан')) : $value,
            'status' => $isEmpty ? 'empty' : 'req',
            'sub' => $isEmpty ? null : $sub,
            'mono' => $mono,
            'bold' => false,
            'small' => false,
        ];
    }

    private function matchCell(string $value, ?string $sub = null): array
    {
        return ['value' => $value, 'status' => 'match', 'sub' => $sub, 'mono' => false, 'bold' => false, 'small' => false];
    }

    private function diffCell(string $value, ?string $sub = null): array
    {
        return ['value' => $value, 'status' => 'diff', 'sub' => $sub, 'mono' => false, 'bold' => false, 'small' => false];
    }

    private function badCell(string $value, ?string $sub = null): array
    {
        return ['value' => $value, 'status' => 'bad', 'sub' => $sub, 'mono' => false, 'bold' => false, 'small' => false];
    }

    private function plainCell(?string $value, bool $mono = false, bool $bold = false, bool $small = false): array
    {
        if ($value === null || $value === '') {
            return $this->emptyCell();
        }
        return ['value' => $value, 'status' => 'plain', 'sub' => null, 'mono' => $mono, 'bold' => $bold, 'small' => $small];
    }

    private function emptyCell(string $placeholder = '—'): array
    {
        return ['value' => $placeholder, 'status' => 'empty', 'sub' => null, 'mono' => false, 'bold' => false, 'small' => false];
    }

    // ---------- Comparators ----------

    private function brandCell(?string $catBrand, ?string $subjBrand): array
    {
        if (! $catBrand) {
            return $this->emptyCell();
        }
        if (! $subjBrand) {
            return $this->plainCell($catBrand);
        }
        $a = mb_strtolower(trim($catBrand));
        $b = mb_strtolower(trim($subjBrand));
        if ($a === $b || mb_strpos($a, $b) !== false || mb_strpos($b, $a) !== false) {
            return $this->matchCell($catBrand);
        }
        return $this->diffCell($catBrand, 'другой бренд');
    }

    private function substringCell(?string $catValue, ?string $subjValue, string $diffNote): array
    {
        if (! $catValue) {
            return $this->emptyCell();
        }
        if (! $subjValue) {
            return $this->plainCell($catValue);
        }
        if (mb_strpos(mb_strtolower($catValue), mb_strtolower($subjValue)) !== false
            || mb_strpos(mb_strtolower($subjValue), mb_strtolower($catValue)) !== false) {
            return $this->matchCell($catValue);
        }
        return $this->diffCell($catValue, $diffNote);
    }

    private function seriesCell(?string $catSeries, ?string $subjSeries): array
    {
        if (! $catSeries) {
            return $this->emptyCell();
        }
        if (! $subjSeries) {
            return $this->plainCell($catSeries);
        }
        if (mb_strtolower($catSeries) === mb_strtolower($subjSeries)) {
            return $this->matchCell($catSeries);
        }
        return $this->diffCell($catSeries, 'другая серия');
    }

    // ---------- Extractors ----------

    /**
     * Грубая эвристика для «Серии / семьи»: ищет паттерны вида
     *   FT 732, TLD OP700, Velino 35, EMOD EM112L, FT-732-GL.
     */
    private function extractSeries(?string $text): ?string
    {
        if (! $text) {
            return null;
        }
        // Большие буквы + цифры (например FT 732, OP700, EMOD EM112L).
        // Используем # как разделитель — иначе / внутри character class
        // ломает регулярку (Unknown modifier '-').
        if (preg_match('#\b([A-Z]{2,}\s?\d{2,}[A-Z0-9/-]*)\b#u', $text, $m)) {
            return trim($m[1]);
        }
        // Имя серии типа Velino, Synergy и т.д. — заглавное слово.
        if (preg_match('#\b([A-Z][a-zA-Z]{4,}(?:\s?\d+°?)?)\b#u', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Извлечь массив целых mm-значений из текста (как в ItemCatalogLinkDialog::subjectDimensions).
     * Покрывает форматы 62x40x10 / 1700 mm / L=1141.
     *
     * @return array<int, int>
     */
    private function extractDimensions(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $dims = [];

        $sepClass = '[\x{00D7}xX\x{0425}\x{0445}*]';
        if (preg_match_all('/(\d{1,5}(?:[.,]\d+)?(?:' . $sepClass . '\d{1,5}(?:[.,]\d+)?)+)/u', $text, $matches)) {
            foreach ($matches[1] as $series) {
                foreach (preg_split('/' . $sepClass . '/u', $series) as $n) {
                    $val = (int) round((float) str_replace(',', '.', trim($n)));
                    if ($val > 0 && $val < 100000) {
                        $dims[] = $val;
                    }
                }
            }
        }
        if (preg_match_all('/(\d{2,5}(?:[.,]\d+)?)\s*(?:\x{043C}\x{043C}|mm)\b/u', $text, $matches)) {
            foreach ($matches[1] as $n) {
                $val = (int) round((float) str_replace(',', '.', $n));
                if ($val > 0 && $val < 100000) {
                    $dims[] = $val;
                }
            }
        }
        if (preg_match_all('/\b[LWHlwh\x{041B}\x{0414}\x{0412}\x{0428}\x{0413}]\s*=\s*(\d{2,5}(?:[.,]\d+)?)/u', $text, $matches)) {
            foreach ($matches[1] as $n) {
                $val = (int) round((float) str_replace(',', '.', $n));
                if ($val > 0 && $val < 100000) {
                    $dims[] = $val;
                }
            }
        }

        $dims = array_values(array_unique($dims));
        sort($dims);
        return $dims;
    }

    /**
     * Превращает meta из CatalogEmbeddingService::topNByQueryText в строку:
     *   "name+brand · 0.94" (top-source first)
     */
    private function formatSource(?array $meta): ?string
    {
        if (! $meta || ! isset($meta['score'])) {
            return null;
        }
        $score = round((float) $meta['score'], 2);
        $sources = [];
        if (! empty($meta['code'])) {
            $sources[] = 'code';
        }
        if (! empty($meta['trgm'])) {
            $sources[] = 'trgm';
        }
        if (! empty($meta['vector'])) {
            $sources[] = 'vector';
        }
        $src = empty($sources) ? ($meta['method'] ?? 'match') : implode('+', $sources);
        return "{$src} · " . number_format($score, 2);
    }
}
