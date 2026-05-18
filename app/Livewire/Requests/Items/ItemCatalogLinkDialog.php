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
     * Результаты текстового поиска (вкладка `text`).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CatalogItem>
     */
    #[Computed]
    public function textResults()
    {
        if ($this->mode !== 'text' || mb_strlen(trim($this->query)) < 2) {
            return collect();
        }
        return app(CatalogSearchService::class)->search($this->query);
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
        if ($this->similarQueryActive !== '') {
            return $editor->findSimilarByQuery($item, $this->similarQueryActive, auth()->user(), 10);
        }
        return $editor->findSimilar($item, auth()->user(), 10);
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
