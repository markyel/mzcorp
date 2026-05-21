<?php

namespace App\Livewire\Requests\Items;

use App\Models\CatalogItem;
use App\Models\EmailAttachment;
use App\Models\RequestItem;
use App\Services\Catalog\CatalogSearchService;
use App\Services\Catalog\RequestItemEditor;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Modal manual link к каталогу (Priority 1+).
 *
 * Две вкладки:
 *  - `text` — поиск по SQL ILIKE через CatalogSearchService (по sku /
 *    brand_article / name);
 *  - `similar` — top-10 vector-similarity через RequestItemEditor::findSimilar
 *    (без threshold/LLM — preview, оператор сам решает).
 *
 * События:
 *  - `open-catalog-link {itemId}` → открывает в режиме `text` с pre-fill.
 *  - `open-catalog-similar {itemId}` → сразу в режиме `similar`.
 *
 * Хранит только id (как ReassignDialog), не Eloquent-модель.
 */
class ItemCatalogLinkDialog extends Component
{
    public int $requestId;
    public ?int $requestItemId = null;
    public bool $open = false;
    /** Активная вкладка: text | similar. */
    public string $mode = 'text';
    public string $query = '';
    /**
     * Свой запрос менеджера для vector-поиска (вкладка similar).
     * По-умолчанию = parsed_name + parsed_article. Менеджер может перетереть.
     */
    public string $similarQuery = '';
    /** Отметка submitted-запроса — то, по чему сейчас отрисованы результаты. */
    public string $similarQueryActive = '';
    public ?int $selectedCatalogId = null;

    /** Список catalog_item_id для side-by-side сравнения (max 3). */
    public array $compareIds = [];
    /** Когда true — modal показывает compare-панель вместо списка. */
    public bool $comparing = false;

    /**
     * Post-fetch chip-фильтры над результатами поиска (text + similar).
     * Применяются в applyChipFilters(). Default OFF — оператор включает
     * руками, чтобы новый UI не «прятал» результаты молча.
     *
     * $filterUnit — exclusive single-select по catalog.unit_name из
     * available list. null = фильтр не применён.
     */
    public bool $filterBrand = false;
    public bool $filterCategory = false;
    public bool $filterDims = false;
    public ?string $filterUnit = null;

    /**
     * Compare-toolbar toggle state.
     *   compareView — 'compare' (grid) | 'list' (текущий простой список)
     */
    public string $compareView = 'compare';

    public const COMPARE_MAX = 8;

    /** Допуск ±N мм при сравнении размеров subject vs catalog.size_a..f. */
    private const DIM_TOLERANCE_MM = 5;

    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;
    }

    #[On('open-catalog-link')]
    public function openForItem(int $itemId): void
    {
        $this->openInMode($itemId, 'text');
    }

    #[On('open-catalog-similar')]
    public function openForItemSimilar(int $itemId): void
    {
        $this->openInMode($itemId, 'similar');
    }

    private function openInMode(int $itemId, string $mode): void
    {
        $item = RequestItem::query()
            ->where('request_id', $this->requestId)
            ->where('is_active', true)
            ->whereKey($itemId)
            ->first();
        if (! $item) {
            return;
        }
        $this->requestItemId = $item->id;
        $this->mode = in_array($mode, ['text', 'similar'], true) ? $mode : 'text';
        // Pre-fill query — для text-режима полезно, для similar используется
        // напрямую RequestItem-данные через embedder.
        $this->query = (string) ($item->parsed_article ?: $item->parsed_name ?: '');
        $this->similarQuery = trim(($item->parsed_name ?? '') . ' ' . ($item->parsed_article ?? ''));
        // Активная отметка пуста → similarResults использует «исходный»
        // путь (parsed_*) до первого ручного «Искать».
        $this->similarQueryActive = '';
        $this->selectedCatalogId = $item->catalog_item_id;
        $this->resetErrorBag();
        $this->open = true;
    }

    public function setMode(string $mode): void
    {
        if (in_array($mode, ['text', 'similar'], true)) {
            $this->mode = $mode;
            $this->selectedCatalogId = null;
        }
    }

    public function close(): void
    {
        $this->open = false;
        $this->requestItemId = null;
        $this->mode = 'text';
        $this->query = '';
        $this->similarQuery = '';
        $this->similarQueryActive = '';
        $this->selectedCatalogId = null;
        $this->compareIds = [];
        $this->comparing = false;
        $this->filterBrand = false;
        $this->filterCategory = false;
        $this->filterDims = false;
        $this->filterUnit = null;
        $this->compareView = 'compare';
    }

    public function toggleBrandFilter(): void
    {
        $this->filterBrand = ! $this->filterBrand;
    }

    public function toggleCategoryFilter(): void
    {
        $this->filterCategory = ! $this->filterCategory;
    }

    public function toggleDimsFilter(): void
    {
        $this->filterDims = ! $this->filterDims;
    }

    /**
     * Exclusive toggle. Кликнул на уже выбранный узел → снять фильтр.
     * Кликнул на другой → перевыбрать (не aggregating multi).
     */
    public function toggleUnitFilter(string $unit): void
    {
        $this->filterUnit = ($this->filterUnit === $unit) ? null : $unit;
    }

    public function setCompareView(string $view): void
    {
        if (in_array($view, ['compare', 'list'], true)) {
            $this->compareView = $view;
        }
    }

    /**
     * Структурированные данные для compare-таблицы (см. CatalogComparisonService::compare).
     * meta из similarResults используется для % match + источник совпадения.
     *
     * @return array{candidates: array, sections: array, subjectQty: int}|null
     */
    #[Computed]
    public function comparisonData(): ?array
    {
        if (! $this->comparing || ! $this->requestItemId) {
            return null;
        }
        $subject = $this->subjectItem;
        if (! $subject) {
            return null;
        }
        // Meta из similarResults (если режим был similar). Для text-режима — пусто,
        // тогда CatalogComparisonService покажет "— text-match" в строке источника.
        $similarityMeta = [];
        foreach ($this->similarResultsBase as $row) {
            $cat = $row['catalog'] ?? null;
            if ($cat instanceof \App\Models\CatalogItem) {
                $similarityMeta[$cat->id] = [
                    'score' => $row['similarity'] ?? null,
                    'method' => $row['method'] ?? null,
                    'code' => $row['code_score'] ?? null,
                    'trgm' => $row['trgm_score'] ?? null,
                    'vector' => $row['vector_score'] ?? null,
                ];
            }
        }

        return app(\App\Services\Catalog\CatalogComparisonService::class)
            ->compare($subject, $this->compareItems, $similarityMeta);
    }

    /**
     * Toggle catalog id в списке для сравнения. Максимум COMPARE_MAX штук.
     */
    public function toggleCompare(int $catalogId): void
    {
        $idx = array_search($catalogId, $this->compareIds, true);
        if ($idx !== false) {
            array_splice($this->compareIds, $idx, 1);
        } elseif (count($this->compareIds) < self::COMPARE_MAX) {
            $this->compareIds[] = $catalogId;
        }
    }

    public function clearCompare(): void
    {
        $this->compareIds = [];
    }

    /**
     * Войти в режим сравнения. Левая колонка — subject-позиция заявки,
     * правые — выбранные catalog-кандидаты (1..COMPARE_MAX). Требует
     * ≥1 выбранного каталога.
     */
    public function enterCompare(): void
    {
        if (count($this->compareIds) >= 1) {
            $this->comparing = true;
        }
    }

    public function exitCompare(): void
    {
        $this->comparing = false;
    }

    /**
     * Применить пользовательский запрос для vector-поиска. Дёргается из
     * UI: Enter в input или клик по «🔍 Искать».
     */
    public function applySimilarQuery(): void
    {
        $trimmed = trim($this->similarQuery);
        $this->similarQuery = $trimmed;
        $this->similarQueryActive = $trimmed;
        $this->selectedCatalogId = null;
        unset($this->similarResults);
    }

    /**
     * Сброс ручного запроса → similarResults снова показывает дефолтную
     * подборку по parsed_name / parsed_article позиции.
     */
    public function resetSimilarQuery(): void
    {
        $this->similarQuery = '';
        $this->similarQueryActive = '';
        $this->selectedCatalogId = null;
        unset($this->similarResults);
    }

    public function selectCatalog(int $catalogId): void
    {
        $this->selectedCatalogId = $catalogId;
    }

    /**
     * Полные данные каталожных позиций для compare-панели.
     * Сохраняет порядок добавления в $compareIds.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CatalogItem>
     */
    #[Computed]
    public function compareItems()
    {
        if (empty($this->compareIds)) {
            return new EloquentCollection();
        }
        $items = CatalogItem::query()
            ->whereIn('id', $this->compareIds)
            ->get()
            ->keyBy('id');
        // Сохранить порядок выбора менеджером.
        $ordered = new EloquentCollection();
        foreach ($this->compareIds as $id) {
            if ($items->has($id)) {
                $ordered->push($items->get($id));
            }
        }
        return $ordered;
    }

    /**
     * Текущая позиция заявки (для контекста в шапке modal'а — оператор
     * видит «к чему» он подбирает каталожный аналог).
     */
    #[Computed]
    public function subjectItem(): ?RequestItem
    {
        if (! $this->requestItemId) {
            return null;
        }
        return RequestItem::query()
            ->with([
                'request:id,internal_code,client_name,client_email,email_message_id',
                'brand:id,name',
                'kbCategory:id,name,slug',
                'imageAttachment:id,email_message_id,filename,mime_type,disk,file_path,size_bytes',
                'catalogItem:id,sku,brand,brand_article,name,price,stock_available,is_active',
            ])
            ->where('request_id', $this->requestId)
            ->whereKey($this->requestItemId)
            ->first();
    }

    /**
     * Все image-вложения письма, к которому привязана заявка. Используется
     * как галерея в шапке modal'а: менеджер может посмотреть все фото из
     * письма, а не только то, которое Vision привязал к этой позиции.
     *
     * Возвращает пустую коллекцию если у заявки нет письма (manual request)
     * или у письма нет картинок.
     *
     * @return EloquentCollection<int, EmailAttachment>
     */
    #[Computed]
    public function emailImages(): EloquentCollection
    {
        if (! $this->requestItemId) {
            return new EloquentCollection();
        }
        $subject = $this->subjectItem;
        $emailMessageId = $subject?->request?->email_message_id;
        if (! $emailMessageId) {
            return new EloquentCollection();
        }
        return EmailAttachment::query()
            ->select(['id', 'email_message_id', 'filename', 'mime_type', 'disk', 'file_path', 'size_bytes'])
            ->where('email_message_id', $emailMessageId)
            ->where('mime_type', 'like', 'image/%')
            ->orderBy('id')
            ->get();
    }

    /**
     * Raw text-search results — without any chip filters applied.
     * Used as a single source for both filtered textResults and availableUnits.
     *
     * @return array<int, array{catalog: CatalogItem, similarity: null}>
     */
    #[Computed]
    public function textResultsBase(): array
    {
        if ($this->mode !== 'text' || mb_strlen(trim($this->query)) < 2) {
            return [];
        }
        return app(CatalogSearchService::class)->search($this->query)
            ->map(fn (CatalogItem $c) => ['catalog' => $c, 'similarity' => null])
            ->all();
    }

    /**
     * Filtered text-search results — passed to UI table.
     *
     * @return array<int, array{catalog: CatalogItem, similarity: null}>
     */
    #[Computed]
    public function textResults(): array
    {
        return $this->applyChipFilters($this->textResultsBase);
    }

    /**
     * Raw similar (vector) results — without chip filters.
     *
     * @return array<int, array{catalog: CatalogItem, similarity: float}>
     */
    #[Computed]
    public function similarResultsBase(): array
    {
        if ($this->mode !== 'similar' || ! $this->requestItemId) {
            return [];
        }
        $item = RequestItem::query()
            ->where('request_id', $this->requestId)
            ->where('is_active', true)
            ->whereKey($this->requestItemId)
            ->first();
        if (! $item) {
            return [];
        }
        @set_time_limit(60);
        $editor = app(RequestItemEditor::class);

        // Если менеджер применил ручной запрос («Плата ПКЛ-32») — ищем по
        // нему. Иначе — дефолтный путь через parsed_name/parsed_article.
        return $this->similarQueryActive !== ''
            ? $editor->findSimilarByQuery($item, $this->similarQueryActive, auth()->user(), 10)
            : $editor->findSimilar($item, auth()->user(), 10);
    }

    /**
     * Top-10 vector-similarity (вкладка `similar`). Поднимает таймаут —
     * embed-запрос к OpenAI может занимать 2-5 сек.
     *
     * @return array<int, array{catalog: CatalogItem, similarity: float}>
     */
    #[Computed]
    public function similarResults(): array
    {
        return $this->applyChipFilters($this->similarResultsBase);
    }

    /**
     * Distinct unit_name values present in current search results AFTER
     * brand/category/dims filters (but BEFORE unit filter). Top-8 by count desc.
     * Used by UI to render "Узел: <name> (count)" chip-row.
     *
     * @return array<string, int>  unit_name => count, sorted desc
     */
    #[Computed]
    public function availableUnits(): array
    {
        $rows = $this->mode === 'text' ? $this->textResultsBase : $this->similarResultsBase;
        $rows = $this->applyBaseChipFilters($rows);
        $counts = [];
        foreach ($rows as $row) {
            $u = $row['catalog']->unit_name;
            if ($u !== null && $u !== '') {
                $key = trim((string) $u);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        arsort($counts);
        return array_slice($counts, 0, 8, true);
    }

    /**
     * Extract numeric dimensions (mm) from subject.parsed_name + parsed_article.
     * Used by filterDims chip.
     *
     * Covers:
     *  - 62x40x10 / 62*40*10 / 62-Cyr-40-Cyr-10 (series via U+00D7 / x / X /
     *    U+0425 / U+0445 / asterisk)
     *  - 1700 + mm postfix (mm = U+043C U+043C cyrillic, or "mm" latin)
     *  - L=1141 / W=200 / H=80 (latin or cyrillic prefix)
     *
     * Comments and regex bodies kept ASCII-only to avoid PHP parser breakage
     * on non-ASCII byte sequences in source (prod ParseError on cyrillic
     * literal inside char class).
     *
     * @return array<int, int>  sorted unique integer mm
     */
    #[Computed]
    public function subjectDimensions(): array
    {
        $subject = $this->subjectItem;
        if (! $subject) {
            return [];
        }
        $text = trim(($subject->parsed_name ?? '') . ' ' . ($subject->parsed_article ?? ''));
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
     * KB category keyword for subject: first word >= 4 chars, lowercase.
     * Used as a substring filter against catalog.name + unit_name + part_type.
     */
    private function subjectCategoryKeyword(): ?string
    {
        $subject = $this->subjectItem;
        $name = $subject?->kbCategory?->name;
        if (! $name) {
            return null;
        }
        if (preg_match('/[\p{L}]{4,}/u', $name, $m)) {
            return mb_strtolower($m[0]);
        }
        return mb_strtolower(trim($name));
    }

    /**
     * Apply chip filters (brand / KB category / dims / unit) to a list of
     * {catalog, similarity} rows. Reindex via array_values.
     *
     * @param  array<int, array{catalog: CatalogItem, similarity: float|null}>  $rows
     * @return array<int, array{catalog: CatalogItem, similarity: float|null}>
     */
    private function applyChipFilters(array $rows): array
    {
        $rows = $this->applyBaseChipFilters($rows);

        // Unit filter applied LAST so availableUnits computed (which uses
        // base-filtered rows) reflects the choice scope correctly.
        if ($this->filterUnit !== null && $this->filterUnit !== '') {
            $needle = mb_strtolower(trim($this->filterUnit));
            $rows = array_filter($rows, function ($row) use ($needle) {
                $u = $row['catalog']->unit_name;
                return $u !== null && mb_strtolower(trim((string) $u)) === $needle;
            });
        }

        return array_values($rows);
    }

    /**
     * Subset of chip filters that DO NOT include the exclusive unit-name
     * filter. Used both by applyChipFilters (then unit-filter is layered on
     * top) and by availableUnits computed (to figure out the choice scope).
     *
     * @param  array<int, array{catalog: CatalogItem, similarity: float|null}>  $rows
     * @return array<int, array{catalog: CatalogItem, similarity: float|null}>
     */
    private function applyBaseChipFilters(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $subject = $this->subjectItem;
        if (! $subject) {
            return $rows;
        }

        if ($this->filterBrand) {
            $brand = $subject->brand?->name ?: $subject->parsed_brand;
            if ($brand) {
                $needle = mb_strtolower(trim($brand));
                // Bidirectional substring match: subject brand may be short
                // ("ThyssenKrupp") while catalog has long form with
                // subsidiary suffix ("ThyssenKrupp Elevator (TKE)"), or
                // vice versa. Treat any inclusion as a match.
                //
                // Помимо primary `brand` проверяем jsonb-массив `brands` —
                // аналоги хранят производителя в `brand` (напр.
                // «Руспромаппаратура»), а совместимые OEM-бренды (OTIS,
                // KONE, ...) — в `brands[]`. Без этого subject brand=Otis
                // отсекает аналоги. См. миграцию 2026_05_19_180000.
                $rows = array_filter($rows, function ($row) use ($needle) {
                    $catalog = $row['catalog'];
                    $candidates = [];
                    if ($catalog->brand !== null && $catalog->brand !== '') {
                        $candidates[] = $catalog->brand;
                    }
                    if (is_array($catalog->brands)) {
                        foreach ($catalog->brands as $b) {
                            if (is_string($b) && $b !== '') {
                                $candidates[] = $b;
                            }
                        }
                    }
                    foreach ($candidates as $b) {
                        $b = mb_strtolower(trim((string) $b));
                        if ($b === '') {
                            continue;
                        }
                        if ($b === $needle
                            || mb_strpos($b, $needle) !== false
                            || mb_strpos($needle, $b) !== false
                        ) {
                            return true;
                        }
                    }
                    return false;
                });
            }
        }

        if ($this->filterCategory) {
            $keyword = $this->subjectCategoryKeyword();
            if ($keyword !== null) {
                $rows = array_filter($rows, function ($row) use ($keyword) {
                    $cat = $row['catalog'];
                    $haystack = mb_strtolower(
                        ($cat->name ?? '') . ' '
                        . ($cat->unit_name ?? '') . ' '
                        . ($cat->part_type ?? '')
                    );
                    return mb_strpos($haystack, $keyword) !== false;
                });
            }
        }

        if ($this->filterDims) {
            $dims = $this->subjectDimensions;
            if (! empty($dims)) {
                $tol = self::DIM_TOLERANCE_MM;
                // 2026-05-21 фикс OR→majority: раньше любой совпавший
                // размер пропускал позицию. Это давало false-positive для
                // ремня 16×1360×2 при поиске ролика 44×16 — совпадение
                // по «16» проходило, хотя 44 нет.
                //
                // Новое правило: считаем сколько subject-размеров нашлось
                // в catalog.size_a..f (±tol). Каждый subject-размер «съедает»
                // ровно один catalog-размер (не один catalog-размер на
                // несколько subject-ов). Позиция проходит если найдено
                // ≥ ceil(|dims|/2) совпадений, но минимум 2 при |dims|≥2
                // (для 2-мерных subject требуем оба).
                $needed = max(1, (int) ceil(count($dims) / 2));
                if (count($dims) >= 2) {
                    $needed = max($needed, 2);
                }
                $rows = array_filter($rows, function ($row) use ($dims, $tol, $needed) {
                    $cat = $row['catalog'];
                    $sizes = array_values(array_filter([
                        $cat->size_a, $cat->size_b, $cat->size_c,
                        $cat->size_d, $cat->size_e, $cat->size_f,
                    ], fn ($v) => $v !== null));
                    if ($sizes === []) {
                        return false;
                    }
                    // Greedy-матчинг с пометкой использованных catalog-размеров,
                    // чтобы «16» в subject не съел «16» в catalog дважды.
                    $used = [];
                    $matched = 0;
                    foreach ($dims as $d) {
                        foreach ($sizes as $i => $s) {
                            if (isset($used[$i])) {
                                continue;
                            }
                            if (abs(((int) round((float) $s)) - $d) <= $tol) {
                                $used[$i] = true;
                                $matched++;
                                break;
                            }
                        }
                    }
                    return $matched >= $needed;
                });
            }
        }

        return $rows;
    }

    public function save(RequestItemEditor $editor): void
    {
        if (! $this->requestItemId || ! $this->selectedCatalogId) {
            $this->addError('query', 'Выберите позицию каталога из списка ниже.');
            return;
        }
        $item = RequestItem::query()
            ->where('request_id', $this->requestId)
            ->where('is_active', true)
            ->whereKey($this->requestItemId)
            ->first();
        $catalog = CatalogItem::find($this->selectedCatalogId);
        if (! $item || ! $catalog) {
            $this->addError('query', 'Позиция или каталожная карточка не найдены.');
            return;
        }

        $editor->linkToCatalog($item, $catalog, auth()->user());

        $this->dispatch('item-relinked');
        $this->close();
    }

    public function render()
    {
        return view('livewire.requests.items.item-catalog-link-dialog');
    }
}
