<?php

namespace App\Livewire\Catalog;

use App\Models\CatalogItem;
use App\Models\Kb\EquipmentCategory;
use App\Services\Catalog\CatalogEmbeddingService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Standalone-поиск по каталогу (без привязки к заявке).
 *
 * Combo-поиск text + vector в одном вызове:
 *   `CatalogEmbeddingService::topNByQueryText()` уже делает merge
 *   code-token (ILIKE substring) + trigram (pg_trgm) + vector (pgvector)
 *   с multi-source бонусом — позиции, найденные несколькими способами,
 *   получают приоритет в выдаче. Здесь дёргается ровно тот же hybrid
 *   pipeline, что использует «Похожие из каталога» в ItemCatalogLinkDialog.
 *
 * Отличие от dialog'а — нет subject-позиции:
 *   - chip-фильтры (бренд / узел / категория KB / размеры) задаются
 *     менеджером вручную, не выводятся из RequestItem;
 *   - результат не «привязывается» — только ссылка на mylift.ru.
 *
 * URL-state: ?q=...&unit=...&brands[]=...&cat=...&dims[]=...
 * — реcurrent-ссылка на поиск шарится из браузера.
 */
class Search extends Component
{
    /** Поисковый запрос — артикул / OEM / название / часть фразы. */
    #[Url(as: 'q')]
    public string $query = '';

    /** Single-select chip узел (catalog.unit_name). null = не фильтруем. */
    #[Url(as: 'unit')]
    public ?string $filterUnit = null;

    /**
     * Multi-select chip брендов: match если subject brand содержится
     * в catalog.brand ИЛИ в любом элементе catalog.brands[] (OEM-кросс).
     *
     * @var array<int, string>
     */
    #[Url(as: 'brands')]
    public array $filterBrands = [];

    /**
     * KB-категория (EquipmentCategory.id). Фильтр через synonyms[]:
     * каталог попадает в выдачу, если name+unit_name+part_type
     * содержит хотя бы один синоним выбранной категории.
     */
    #[Url(as: 'cat')]
    public ?int $filterCategoryId = null;

    /**
     * Размеры (мм) — multi-chip. Match если хотя бы один size_a..f
     * каталога ±DIM_TOLERANCE_MM от любого из заданных значений.
     *
     * @var array<int, int>
     */
    #[Url(as: 'dims')]
    public array $filterDims = [];

    /** Сюда пишется значение из input «добавить размер». */
    public string $newDim = '';

    /** Допуск ±N мм при сравнении размеров. Тот же что в ItemCatalogLinkDialog. */
    private const DIM_TOLERANCE_MM = 5;

    /** Сколько каталог-позиций тянем из embedder'а до chip-фильтров. */
    private const RESULT_POOL = 50;

    public function updatedQuery(): void
    {
        // Сброс пагинации/выбора при смене запроса не нужен — стейта нет.
        // Просто инвалидируем computed-кеш для немедленного перерасчёта.
        unset($this->resultsBase);
    }

    public function toggleUnit(string $unit): void
    {
        $this->filterUnit = ($this->filterUnit === $unit) ? null : $unit;
    }

    public function toggleBrand(string $brand): void
    {
        $brand = trim($brand);
        if ($brand === '') {
            return;
        }
        $idx = array_search($brand, $this->filterBrands, true);
        if ($idx !== false) {
            array_splice($this->filterBrands, $idx, 1);
        } else {
            $this->filterBrands[] = $brand;
        }
    }

    public function setCategory(?int $id): void
    {
        $this->filterCategoryId = $id ?: null;
    }

    public function addDim(): void
    {
        $val = (int) round((float) str_replace(',', '.', trim($this->newDim)));
        $this->newDim = '';
        if ($val <= 0 || $val > 100000) {
            return;
        }
        if (! in_array($val, $this->filterDims, true)) {
            $this->filterDims[] = $val;
            sort($this->filterDims);
        }
    }

    public function removeDim(int $val): void
    {
        $idx = array_search($val, $this->filterDims, true);
        if ($idx !== false) {
            array_splice($this->filterDims, $idx, 1);
        }
    }

    public function clearFilters(): void
    {
        $this->filterUnit = null;
        $this->filterBrands = [];
        $this->filterCategoryId = null;
        $this->filterDims = [];
        $this->newDim = '';
    }

    /**
     * Combo-выдача code+trgm+vector — см. CatalogEmbeddingService::topNByQueryText.
     * Multi-source бонус (+0.05 за каждый дополнительный источник)
     * автоматически поднимает позиции «нашлось обоими путями» наверх.
     *
     * @return array<int, array{catalog: CatalogItem, similarity: float, method: string, code_score: ?float, trgm_score: ?float, vector_score: ?float}>
     */
    #[Computed]
    public function resultsBase(): array
    {
        $q = trim($this->query);
        if (mb_strlen($q) < 2) {
            return [];
        }
        // Поднимаем таймаут: vector-fallback может занимать до 2 сек
        // (OpenAI embed call). Для standalone-поиска это приемлемо.
        @set_time_limit(60);
        return app(CatalogEmbeddingService::class)->topNByQueryText($q, self::RESULT_POOL);
    }

    /**
     * Distinct unit_name значения из текущей выдачи AFTER brand/category/dims
     * фильтров (но BEFORE unit-фильтра). Top-12 по count desc.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function availableUnits(): array
    {
        $rows = $this->applyFilters($this->resultsBase, skipUnit: true);
        $counts = [];
        foreach ($rows as $row) {
            $u = $row['catalog']->unit_name;
            if (! is_string($u) || trim($u) === '') {
                continue;
            }
            $key = trim($u);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);
        return array_slice($counts, 0, 12, true);
    }

    /**
     * Distinct бренды из выдачи AFTER unit/category/dims фильтров
     * (но BEFORE brand-фильтра). Учитывает и primary `brand`, и jsonb `brands[]`
     * — multi-brand позиции (M01231 Otis-аналог) дают каждый OEM-кросс отдельно.
     *
     * Top-12 по count desc.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function availableBrands(): array
    {
        $rows = $this->applyFilters($this->resultsBase, skipBrand: true);
        $counts = [];
        foreach ($rows as $row) {
            $cat = $row['catalog'];
            $brands = [];
            if (is_string($cat->brand) && trim($cat->brand) !== '') {
                $brands[] = trim($cat->brand);
            }
            if (is_array($cat->brands)) {
                foreach ($cat->brands as $b) {
                    if (is_string($b) && trim($b) !== '') {
                        $brands[] = trim($b);
                    }
                }
            }
            // Уникализируем внутри одного catalog row, чтобы дубли в brands[]
            // (M01231 имеет brands=[Руспромаппаратура, OTIS, OTIS, OTIS, OTIS])
            // не накручивали счётчик одного и того же бренда в +4.
            foreach (array_unique($brands) as $b) {
                $counts[$b] = ($counts[$b] ?? 0) + 1;
            }
        }
        arsort($counts);
        return array_slice($counts, 0, 12, true);
    }

    /**
     * Финальная отфильтрованная выдача — то, что показывается в таблице.
     *
     * @return array<int, array{catalog: CatalogItem, similarity: float, method: string, code_score: ?float, trgm_score: ?float, vector_score: ?float}>
     */
    #[Computed]
    public function results(): array
    {
        return $this->applyFilters($this->resultsBase);
    }

    /**
     * Active KB-категории для select-dropdown'а.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EquipmentCategory>
     */
    #[Computed]
    public function kbCategories()
    {
        return EquipmentCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'synonyms']);
    }

    /**
     * Apply chip-filters (brand / unit / kb-category / dims).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(array $rows, bool $skipBrand = false, bool $skipUnit = false): array
    {
        if ($rows === []) {
            return $rows;
        }

        if (! $skipBrand && ! empty($this->filterBrands)) {
            $needles = array_map(fn ($b) => mb_strtolower(trim($b)), $this->filterBrands);
            $rows = array_filter($rows, function ($row) use ($needles) {
                $catalog = $row['catalog'];
                $candidates = [];
                if (is_string($catalog->brand) && $catalog->brand !== '') {
                    $candidates[] = mb_strtolower(trim($catalog->brand));
                }
                if (is_array($catalog->brands)) {
                    foreach ($catalog->brands as $b) {
                        if (is_string($b) && $b !== '') {
                            $candidates[] = mb_strtolower(trim($b));
                        }
                    }
                }
                foreach ($candidates as $b) {
                    if ($b === '') continue;
                    foreach ($needles as $n) {
                        // Bidirectional substring match — subject "Otis"
                        // должен ловить и "OTIS", и "OTIS Elevator".
                        if ($b === $n
                            || mb_strpos($b, $n) !== false
                            || mb_strpos($n, $b) !== false) {
                            return true;
                        }
                    }
                }
                return false;
            });
        }

        if (! $skipUnit && $this->filterUnit !== null && $this->filterUnit !== '') {
            $needle = mb_strtolower(trim($this->filterUnit));
            $rows = array_filter($rows, function ($row) use ($needle) {
                $u = $row['catalog']->unit_name;
                return is_string($u) && mb_strtolower(trim($u)) === $needle;
            });
        }

        if ($this->filterCategoryId !== null) {
            $targetId = (int) $this->filterCategoryId;

            // Phase B (2026-05-21): фильтр опирается на FK catalog_items.equipment_category_id,
            // заполненный командой `kb:backfill-categories`. Точно, без морфологических
            // казусов substring-matcher'а.
            //
            // Fallback на старую synonym-логику оставлен для legacy SKU где FK ещё NULL —
            // чтобы поиск не пропадал до полного прогона backfill. Эту ветку можно убрать
            // когда все catalog_items будут классифицированы.
            $cat = $this->kbCategories->firstWhere('id', $targetId);
            $synonyms = [];
            if ($cat) {
                $synonyms[] = mb_strtolower($cat->name);
                if (is_array($cat->synonyms)) {
                    foreach ($cat->synonyms as $syn) {
                        if (is_string($syn) && $syn !== '') {
                            $synonyms[] = mb_strtolower($syn);
                        }
                    }
                }
            }

            $rows = array_filter($rows, function ($row) use ($targetId, $synonyms) {
                $c = $row['catalog'];

                // 1. Точный FK-матч.
                if ((int) ($c->equipment_category_id ?? 0) === $targetId) {
                    return true;
                }

                // 2. FK у позиции стоит, но НЕ совпадает с target — категория явно другая, режем.
                if (($c->equipment_category_id ?? null) !== null) {
                    return false;
                }

                // 3. Legacy SKU без FK — fallback на substring synonym match.
                if ($synonyms === []) {
                    return false;
                }
                $haystack = mb_strtolower(
                    ($c->name ?? '') . ' '
                    . ($c->unit_name ?? '') . ' '
                    . ($c->part_type ?? '')
                );
                foreach ($synonyms as $syn) {
                    if ($syn === '') continue;
                    $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($syn, '/') . '(?![\p{L}\p{N}_])/u';
                    if (preg_match($pattern, $haystack)) {
                        return true;
                    }
                }
                return false;
            });
        }

        if (! empty($this->filterDims)) {
            $tol = self::DIM_TOLERANCE_MM;
            $dims = $this->filterDims;
            $rows = array_filter($rows, function ($row) use ($dims, $tol) {
                $c = $row['catalog'];
                $sizes = array_filter([
                    $c->size_a, $c->size_b, $c->size_c,
                    $c->size_d, $c->size_e, $c->size_f,
                ], fn ($v) => $v !== null);
                if ($sizes === []) {
                    return false;
                }
                foreach ($sizes as $s) {
                    $sInt = (int) round((float) $s);
                    foreach ($dims as $d) {
                        if (abs($sInt - $d) <= $tol) {
                            return true;
                        }
                    }
                }
                return false;
            });
        }

        return array_values($rows);
    }

    public function render()
    {
        return view('livewire.catalog.search');
    }
}
