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
     */
    public bool $filterBrand = false;
    public bool $filterCategory = false;
    public bool $filterDims = false;

    public const COMPARE_MAX = 3;

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
     * Результаты текстового поиска (вкладка `text`). Возвращается в том же
     * формате, что similarResults — массив `{catalog, similarity:null}`,
     * чтобы один partial _catalog-results-table рендерил оба и applyChipFilters
     * работал единообразно.
     *
     * @return array<int, array{catalog: CatalogItem, similarity: null}>
     */
    #[Computed]
    public function textResults(): array
    {
        if ($this->mode !== 'text' || mb_strlen(trim($this->query)) < 2) {
            return [];
        }
        $rows = app(CatalogSearchService::class)->search($this->query)
            ->map(fn (CatalogItem $c) => ['catalog' => $c, 'similarity' => null])
            ->all();

        return $this->applyChipFilters($rows);
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
        $rows = $this->similarQueryActive !== ''
            ? $editor->findSimilarByQuery($item, $this->similarQueryActive, auth()->user(), 10)
            : $editor->findSimilar($item, auth()->user(), 10);

        return $this->applyChipFilters($rows);
    }

    /**
     * Извлечь набор числовых размеров (мм) из parsed_name + parsed_article
     * subject-позиции. Используется фильтром `filterDims`.
     *
     * Покрывает форматы:
     *  - «62×40×10 мм» / «62x40x10» / «62*40*10» / «62Х40Х10» — серия чисел через ×/x/*/Х
     *  - «1700 мм» / «1141.5 мм» — одиночное число с постфиксом мм/mm
     *  - «L=1141.5» — после префикса L=, ширина W=, высота H=
     *
     * @return array<int, int>  отсортированный набор уникальных целых mm
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

        // Pattern: 62×40×10 — серия чисел через ×/x/X/х/Х/*
        if (preg_match_all('/(\d{1,5}(?:[.,]\d+)?(?:[\x{00D7}xXхХ*]\d{1,5}(?:[.,]\d+)?)+)/u', $text, $matches)) {
            foreach ($matches[1] as $series) {
                foreach (preg_split('/[\x{00D7}xXхХ*]/u', $series) as $n) {
                    $val = (int) round((float) str_replace(',', '.', trim($n)));
                    if ($val > 0 && $val < 100000) {
                        $dims[] = $val;
                    }
                }
            }
        }

        // Pattern: 1700 мм / 1141.5 мм
        if (preg_match_all('/(\d{2,5}(?:[.,]\d+)?)\s*мм\b/u', $text, $matches)) {
            foreach ($matches[1] as $n) {
                $val = (int) round((float) str_replace(',', '.', $n));
                if ($val > 0 && $val < 100000) {
                    $dims[] = $val;
                }
            }
        }

        // Pattern: L=1141 / W=200 / H=80
        if (preg_match_all('/\b[LWHЛДВШГlwh]\s*=\s*(\d{2,5}(?:[.,]\d+)?)/u', $text, $matches)) {
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
     * Главное keyword KB-категории subject — первое слово ≥4 символов,
     * lowercase, без окончания. Используется substring-фильтром по
     * `catalog.name + unit_name + part_type`.
     */
    private function subjectCategoryKeyword(): ?string
    {
        $subject = $this->subjectItem;
        $name = $subject?->kbCategory?->name;
        if (! $name) {
            return null;
        }
        // Первое слово ≥4 символов (избегаем предлогов «без», «для», «над»).
        if (preg_match('/[\p{L}]{4,}/u', $name, $m)) {
            return mb_strtolower($m[0]);
        }
        return mb_strtolower(trim($name));
    }

    /**
     * Применить chip-фильтры (бренд / KB-категория / размеры) к набору
     * результатов поиска. Принимает list-of-{catalog, similarity, ...},
     * возвращает отфильтрованный list. Reindex через array_values.
     *
     * @param  array<int, array{catalog: CatalogItem, similarity: float|null}>  $rows
     * @return array<int, array{catalog: CatalogItem, similarity: float|null}>
     */
    private function applyChipFilters(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $subject = $this->subjectItem;
        if (! $subject) {
            return $rows;
        }

        // Brand: exact match (case-insensitive trim).
        if ($this->filterBrand) {
            $brand = $subject->brand?->name ?: $subject->parsed_brand;
            if ($brand) {
                $needle = mb_strtolower(trim($brand));
                $rows = array_filter($rows, function ($row) use ($needle) {
                    $b = $row['catalog']->brand;
                    return $b && mb_strtolower(trim($b)) === $needle;
                });
            }
        }

        // Category: substring keyword из subject.kbCategory.name по
        // catalog.name / unit_name / part_type. Эвристика — каталог не
        // хранит structured-category, поэтому матчим по тексту.
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

        // Dims: хотя бы один из subject-dims попадает в любой size_a..f
        // каталога с допуском ±DIM_TOLERANCE_MM.
        if ($this->filterDims) {
            $dims = $this->subjectDimensions;
            if (! empty($dims)) {
                $tol = self::DIM_TOLERANCE_MM;
                $rows = array_filter($rows, function ($row) use ($dims, $tol) {
                    $cat = $row['catalog'];
                    $sizes = array_filter([
                        $cat->size_a, $cat->size_b, $cat->size_c,
                        $cat->size_d, $cat->size_e, $cat->size_f,
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
        }

        return array_values($rows);
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
