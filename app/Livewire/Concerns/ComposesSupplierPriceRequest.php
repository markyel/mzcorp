<?php

namespace App\Livewire\Concerns;

use App\Enums\Role;
use App\Models\CatalogItem;
use App\Models\PriceMonitor;
use App\Models\Supplier;
use App\Services\Catalog\CatalogSearchService;
use App\Services\Procurement\PriceMonitorService;
use App\Services\QuoteParts\QuotePartsPilot;
use App\Services\QuoteParts\QuotePartsRfqService;
use App\Services\Supplier\SupplierItemTranslator;
use App\Services\Supplier\SupplierMatchService;
use App\Services\Supplier\SupplierOrganizationService;
use App\Services\Supplier\SupplierProcurementDispatchService;
use Livewire\Attributes\Computed;
use Throwable;

/**
 * Запрос цен поставщикам по каталожным позициям — панель «Запрос поставщикам»: подбор и группы
 * поставщиков, превью письма по языкам (название, артикул/OEM, количество, M-артикул), перевод,
 * карточка товара с историей поставщиков, фото и файлы, мониторинг цен. Вынесено из «Снабжения»
 * (11.11.2026), чтобы та же панель работала в разделе «QuoteParts»; разметка —
 * livewire/procurement/_supplier-request-panel.
 *
 * Компонент держит отмеченные позиции в $selected (catalog_item_id => bool) и может переопределить
 * afterSupplierRequestReset() (сбросить свои вычисляемые списки) и supplierRequestStatus() (показать итог).
 */
trait ComposesSupplierPriceRequest
{
    /** @var array загруженные файлы/фото для прикрепления к RFQ */
    public $rfqFiles = [];

    /** Приложить фото выбранных позиций из каталога (photo_url → CatalogPhotoCache). */
    public bool $attachCatalogPhotos = false;

    public bool $monitorEnabled = false;

    /** Период перезапроса цены, дней. */
    public int $monitorDays = PriceMonitor::DEFAULT_INTERVAL_DAYS;

    /** Поиск по каталогу для запроса расценки на ЛЮБУЮ позицию (не только блокеры). */
    public string $catalogSearch = '';

    /** supplier_id => bool — кому слать. */
    public array $selectedSuppliers = [];

    /** Доп. поставщики, добавленные вручную (вне матча). */
    public array $addedSupplierIds = [];

    public string $supplierSearch = '';

    /** Развёрнутые карточки товара: «t:<cid>» — строка таблицы, «p:<cid>» — позиция в запросе. */
    public array $openCards = [];

    public string $greeting = 'Здравствуйте, {поставщик}!';

    public string $greetingEn = 'Hello {поставщик},';

    /** Текст вопроса (вступление) — редактируемый, по языку письма. */
    public string $intro = 'Просим дать цену, наличие и срок поставки на следующие позиции:';

    public string $introEn = 'Please provide the price, availability and lead time for the following items:';

    /** Закрывающая строка письма — редактируемая, по языку письма. */
    public string $closing = 'Отвечайте, пожалуйста, на это письмо, сохраняя тему переписки.';

    public string $closingEn = 'Please reply to this email keeping the subject line unchanged.';

    public string $note = '';

    /** cid => название для письма (рус.). */
    public array $editedNames = [];

    /** cid => название для письма (англ.; каталог → name_en, иначе LLM-перевод). */
    public array $editedNamesEn = [];

    /** cid => артикул/OEM. */
    public array $editedOem = [];

    /** cid => количество строкой (рус.). */
    public array $editedQty = [];

    /** cid => количество строкой (англ.). */
    public array $editedQtyEn = [];

    /** Срок ответа для запроса через QuoteParts. */
    public string $qpReplyBy = '';

    #[Computed]
    public function quotePartsEnabled(): bool
    {
        return QuotePartsPilot::enabledFor(auth()->user());
    }

    /**
     * Позиции каталога в порядке запроса (как отмечены): письмо поставщику и запрос в QuoteParts — в одном порядке,
     * иначе «1» в ответе поставщика указывает не на ту позицию (MZ2026-00125, 12.11.2026).
     *
     * @param  list<int>  $cids
     * @return \Illuminate\Support\Collection<int, CatalogItem>
     */
    private function catalogInRequestOrder(array $cids): \Illuminate\Support\Collection
    {
        $pos = array_flip(array_values(array_map('intval', $cids)));

        return CatalogItem::query()->whereIn('id', $cids)->get(['id', 'sku', 'name', 'name_en', 'brand', 'brand_article', 'articles'])
            ->sortBy(fn (CatalogItem $ci) => $pos[(int) $ci->id] ?? PHP_INT_MAX)->values();
    }

    /**
     * Часть рассылки поставщикам, принявшим приглашение QuoteParts: один запрос через платформу.
     *
     * @param  list<int>  $cids
     * @param  list<int>  $supplierIds
     */
    private function routeToQuoteParts(array $cids, array $supplierIds, array $files = [], array $letters = []): string
    {
        $quantities = [];
        foreach ($cids as $cid) {
            $raw = trim((string) ($this->editedQty[$cid] ?? ''));
            // Пусто — без количества: нужна актуальная цена за штуку (актуализация, 11.11.2026), не «1 шт.».
            $q = preg_match('/\d+(?:[.,]\d+)?/', $raw, $m) ? (float) str_replace(',', '.', $m[0]) : 0.0;
            $quantities[$cid] = $q > 0 ? $q : null;
        }
        try {
            // Отмечен один представитель поставщика — получателя выбирает QuoteParts; иначе — отмеченные адреса.
            $explicit = [];
            foreach (Supplier::query()->whereIn('id', $supplierIds)->get(['id', 'supplier_organization_id'])->groupBy(fn ($s) => (int) $s->supplier_organization_id) as $orgId => $g) {
                $ids = $g->pluck('id')->map(fn ($v) => (int) $v)->all();
                if ($orgId === 0 || $ids !== [\App\Services\Supplier\SupplierMatchService::representativeId($orgId)]) {
                    array_push($explicit, ...$ids);
                }
            }
            // Системным письмо пишет QuoteParts по карточке: правленый артикул — строкой «Part No.: …» под позицией.
            $notes = $letters === []
                ? QuotePartsRfqService::articleNotes(CatalogItem::query()->whereIn('id', $cids)->get(['id', 'sku', 'brand_article']), $this->editedOem)
                : [];
            // Как спросили — те же строки и порядок, что в письме: ответ поставщика сверяется с ними (12.11.2026).
            $asked = [];
            foreach (app(SupplierProcurementDispatchService::class)->askedRows($this->catalogInRequestOrder($cids), $this->letterEdits()) as $cid => $row) {
                $asked['c:'.$cid] = $row;
            }
            $rfq = app(QuotePartsRfqService::class)->send(null, $quantities, $supplierIds, $this->qpReplyBy ?: null, $this->note, auth()->user(), [], routed: true, files: $files, notes: $notes, letters: $letters, explicitSupplierIds: $explicit, asked: $asked);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('QuoteParts RFQ refused', ['procurement' => true, 'offsystem' => $letters !== [], 'error' => $e->getMessage()]);

            return 'QuoteParts не принял запрос ('.mb_substr($e->getMessage(), 0, 200).') — этим поставщикам не отправлено.';
        }
        if ($letters !== []) {
            return 'Внесистемным через QuoteParts (письмо с orders@): запрос '.$rfq->tag.', поставщиков '.count($supplierIds).' — ответы придут копией вам.';
        }

        return 'Через QuoteParts: запрос '.$rfq->tag.', поставщиков '.count($supplierIds).' (приняли приглашение).';
    }

    /** Правки письма: названия/кол-во по языкам, артикул, обращение, вступление, заключение. */
    private function letterEdits(): array
    {
        return [
            'names_ru' => $this->editedNames,
            'names_en' => $this->editedNamesEn,
            'oem' => $this->editedOem,
            'qty' => $this->editedQty,
            'qty_en' => $this->editedQtyEn,
            'greeting_ru' => $this->greeting,
            'greeting_en' => $this->greetingEn,
            'intro_ru' => $this->intro,
            'intro_en' => $this->introEn,
            'closing_ru' => $this->closing,
            'closing_en' => $this->closingEn,
        ];
    }

    /** При выборе позиции — префилл редактируемых полей из каталога. */
    public function updatedSelected(): void
    {
        $this->prefillSelectedFields();
        $this->autoTranslateIfEnglish();
    }

    public function updatedSelectedSuppliers(): void
    {
        $this->prefillSelectedFields();
        $this->autoTranslateIfEnglish();
    }

    /**
     * Префилл редактируемых полей (рус. название, артикул, кол-во) по ВСЕМУ
     * набору выбранных позиций из каталога — идемпотентно, не затирая ручные
     * правки. Важно делать по всему набору, а не только по переключённой строке:
     * Livewire объединяет частые .live-апдейты чекбоксов в один запрос, и хук
     * updatedSelected срабатывает не на каждый cid. Иначе у части позиций рус.
     * название оставалось пустым, тогда как EN-набор всегда полон (его так же
     * по всему набору наполняет runEnglishTranslation).
     */
    private function prefillSelectedFields(): void
    {
        $missing = array_values(array_filter(
            $this->selectedCids(),
            fn ($cid) => ! isset($this->editedNames[$cid]),
        ));
        if ($missing === []) {
            return;
        }
        $items = CatalogItem::query()->whereIn('id', $missing)
            ->get(['id', 'sku', 'name', 'name_en', 'brand_article', 'articles']);
        foreach ($items as $ci) {
            $this->editedNames[$ci->id] = (string) ($ci->name ?? '');
            $this->editedNamesEn[$ci->id] ??= (string) ($ci->name_en ?: $ci->name ?? '');
            // Дефолт артикула — OEM без нашего M-кода (oemForExternal пропускает
            // M-артикул, даже если он первый в brand_article/articles[]).
            $this->editedOem[$ci->id] ??= (string) ($ci->oemForExternal() ?? '');
            $this->editedQty[$ci->id] ??= '';
            $this->editedQtyEn[$ci->id] ??= '';
        }
    }

    /* ----------------------- Превью письма по языкам ---------------------- */

    /**
     * Языковые блоки превью: по одному на каждый язык среди ОТМЕЧЕННЫХ
     * поставщиков (ru перед en). Пока поставщики не выбраны — один RU-блок.
     *
     * @return array<int, array{lang:string, label:string, suppliers:array<int,string>, greeting_model:string, intro_model:string, closing_model:string}>
     */
    #[Computed]
    public function previewLanguages(): array
    {
        $ids = array_values(array_map('intval', array_keys(array_filter($this->selectedSuppliers))));
        $suppliers = $ids === [] ? collect() : Supplier::query()->whereIn('id', $ids)->orderBy('name')->get();

        $groups = [];
        foreach ($suppliers as $s) {
            $lang = in_array($s->language, ['ru', 'en'], true) ? $s->language : 'ru';
            $groups[$lang][] = $s;
        }
        if ($groups === []) {
            $groups['ru'] = [];
        }

        $out = [];
        foreach (['ru', 'en'] as $lang) {
            if (! array_key_exists($lang, $groups)) {
                continue;
            }
            $out[] = [
                'lang' => $lang,
                'label' => $lang === 'en' ? 'English' : 'Русский',
                'suppliers' => array_map(fn ($s) => (string) ($s->name ?: $s->email ?: ('#'.$s->id)), $groups[$lang]),
                'greeting_model' => $lang === 'en' ? 'greetingEn' : 'greeting',
                'intro_model' => $lang === 'en' ? 'introEn' : 'intro',
                'closing_model' => $lang === 'en' ? 'closingEn' : 'closing',
            ];
        }

        return $out;
    }

    /**
     * Строки превью на языке: модели для name (editedNames/editedNamesEn),
     * артикула (editedOem), кол-ва (editedQty/editedQtyEn) + флаг cyrillic для
     * EN-названий, оставшихся кириллицей.
     *
     * @return array<int, array{cid:int, name_model:string, qty_model:string, cyrillic:bool}>
     */
    public function previewRowsForLang(string $lang): array
    {
        $lang = $lang === 'en' ? 'en' : 'ru';
        $out = [];
        foreach ($this->selectedPositions as $ci) {
            $enName = trim((string) ($this->editedNamesEn[$ci->id] ?? ''));
            $out[] = [
                'cid' => $ci->id,
                'name_model' => $lang === 'en' ? 'editedNamesEn' : 'editedNames',
                'qty_model' => $lang === 'en' ? 'editedQtyEn' : 'editedQty',
                'cyrillic' => $lang === 'en' && preg_match('/\p{Cyrillic}/u', $enName) === 1,
            ];
        }

        return $out;
    }

    public function translateToEnglish(SupplierItemTranslator $translator): void
    {
        $this->runEnglishTranslation($translator, silent: false);
    }

    /**
     * EN-названия выбранных позиций: каталожный name_en — готовое; пустые/
     * кириллические — LLM-перевод. Транзиентный сбой LLM → оставляем как есть.
     */
    private function runEnglishTranslation(SupplierItemTranslator $translator, bool $silent): void
    {
        $cids = $this->selectedCids();
        if ($cids === []) {
            return;
        }
        $items = CatalogItem::query()->whereIn('id', $cids)->get(['id', 'name', 'name_en']);

        $toTranslate = [];
        foreach ($items as $ci) {
            if (trim((string) $ci->name_en) !== '') {
                $this->editedNamesEn[$ci->id] = $ci->name_en;

                continue;
            }
            $current = trim((string) ($this->editedNamesEn[$ci->id] ?? ''));
            if ($current !== '' && preg_match('/\p{Cyrillic}/u', $current) !== 1) {
                continue;
            }
            $src = trim((string) ($this->editedNames[$ci->id] ?? '')) ?: (string) ($ci->name ?? '');
            if ($src !== '') {
                $toTranslate[$ci->id] = $src;
            }
        }

        $translated = $toTranslate !== [] ? $translator->translate($toTranslate) : [];
        foreach ($translated as $id => $en) {
            $this->editedNamesEn[$id] = $en;
        }

        if ($silent) {
            return;
        }
        $missed = count($toTranslate) - count($translated);
        if ($toTranslate !== [] && $translated === []) {
            $this->dispatch('toast', message: 'Не удалось перевести (LLM недоступен) — попробуйте позже.', type: 'error');
        } else {
            $this->dispatch('toast', message: $missed > 0 ? "Переведено. Не удалось: {$missed}." : 'Названия переведены на английский.', type: $missed > 0 ? 'warning' : 'success');
        }
    }

    private function autoTranslateIfEnglish(): void
    {
        if (! $this->hasEnglishSupplierSelected()) {
            return;
        }
        $this->runEnglishTranslation(app(SupplierItemTranslator::class), silent: true);
    }

    private function hasEnglishSupplierSelected(): bool
    {
        $ids = array_values(array_map('intval', array_keys(array_filter($this->selectedSuppliers))));
        if ($ids === []) {
            return false;
        }

        return Supplier::query()->whereIn('id', $ids)->where('language', 'en')->exists();
    }

    /** @return array<int, int> выбранные catalog_item_id */
    private function selectedCids(): array
    {
        return array_values(array_map('intval', array_keys(array_filter($this->selected))));
    }

    /**
     * Поиск по ВСЕМУ каталогу — чтобы снабженец мог запросить расценку на любую
     * позицию, а не только из списка блокеров. Уже выбранные исключаем.
     *
     * @return \Illuminate\Support\Collection<int, CatalogItem>
     */
    #[Computed]
    public function catalogSearchResults()
    {
        $q = trim($this->catalogSearch);
        if (mb_strlen($q) < 2) {
            return collect();
        }
        $selected = $this->selectedCids();

        return app(CatalogSearchService::class)->search($q, 20)
            ->reject(fn (CatalogItem $ci) => in_array($ci->id, $selected, true))
            ->take(10)
            ->values();
    }

    /** Список артикулов, вставленный разом (столбец из Excel, через запятую и т.п.). */
    public string $skuList = '';

    /** Итог последнего добавления списком: сколько добавлено, чего нет в каталоге. */
    public ?array $skuListReport = null;

    /**
     * Добавить в запрос сразу все M-артикулы из списка — снабженцу не искать по
     * одному (заказчик, 13.10.2026). Кириллическая «М», пробел или дефис после
     * буквы, пропущенные ведущие нули — частые ошибки, их прощаем. Снятые позиции
     * («ЗАМЕНЕНО НА») берём, только если активной с тем же артикулом нет.
     */
    public function addCatalogSkus(): void
    {
        ['wanted' => $wanted, 'found' => $found, 'missing' => $missing] = \App\Services\Catalog\CatalogSkuList::resolve($this->skuList);
        if ($wanted === []) {
            $this->skuListReport = ['added' => 0, 'already' => 0, 'missing' => [], 'empty' => true];

            return;
        }

        $added = 0;
        $already = 0;
        foreach ($wanted as $sku) {
            $ci = $found->get($sku);
            if ($ci === null) {
                continue;
            }
            if (! empty($this->selected[$ci->id])) {
                $already++;

                continue;
            }
            $this->selected[$ci->id] = true;
            $added++;
        }

        $this->skuListReport = ['added' => $added, 'already' => $already, 'missing' => $missing, 'empty' => false];
        if ($added > 0) {
            // Программное добавление не триггерит updatedSelected — префилл вручную.
            $this->prefillSelectedFields();
            $this->autoTranslateIfEnglish();
            unset(
                $this->selectedPositions,
                $this->oemOptions,
                $this->supplierOptions,
                $this->previewLanguages,
                $this->catalogSearchResults,
            );
        }
        if ($missing === []) {
            $this->skuList = '';
        } else {
            // Остаются ненайденные — их удобно поправить и добавить ещё раз.
            $this->skuList = implode("\n", $missing);
        }
    }

    /** Добавить произвольную позицию каталога в набор для запроса. */
    public function addCatalogItem(int $cid): void
    {
        if ($cid <= 0 || isset($this->selected[$cid])) {
            return;
        }
        if (! CatalogItem::whereKey($cid)->exists()) {
            return;
        }
        $this->selected[$cid] = true;
        // Программное добавление не триггерит updatedSelected — префилл вручную.
        $this->prefillSelectedFields();
        $this->autoTranslateIfEnglish();
        $this->catalogSearch = '';
        unset(
            $this->selectedPositions,
            $this->oemOptions,
            $this->supplierOptions,
            $this->previewLanguages,
            $this->catalogSearchResults,
        );
    }

    /** Выбранные позиции (для панели запроса). */
    #[Computed]
    public function selectedPositions()
    {
        $cids = $this->selectedCids();
        if ($cids === []) {
            return collect();
        }

        return CatalogItem::query()->whereIn('id', $cids)
            ->orderBy('sku')->get(['id', 'sku', 'name', 'brand', 'brand_article', 'brands', 'articles']);
    }

    /**
     * Превью M-артикула для каждой выбранной позиции: sku + список доступных
     * OEM-артикулов каталога (brand_article + articles[], без наших M-кодов),
     * с брендом по параллельному индексу. Для попапа «показать OEM» с быстрым
     * добавлением в поле «Артикул/OEM» письма (editedOem).
     *
     * @return array<int, array{sku:string, name:string, options:array<int, array{article:string, brand:string}>}>
     */
    #[Computed]
    public function oemOptions(): array
    {
        $out = [];
        foreach ($this->selectedPositions as $ci) {
            $articles = array_merge([$ci->brand_article], (array) ($ci->articles ?? []));
            $brands = array_merge([$ci->brand], (array) ($ci->brands ?? []));
            $seen = [];
            $list = [];
            foreach ($articles as $idx => $art) {
                $art = trim((string) $art);
                if ($art === '' || CatalogItem::isInternalCode($art, $ci->sku)) {
                    continue;
                }
                $key = mb_strtolower($art);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $list[] = ['article' => $art, 'brand' => trim((string) ($brands[$idx] ?? ''))];
            }
            $out[$ci->id] = ['sku' => (string) $ci->sku, 'name' => (string) $ci->name, 'options' => $list];
        }

        return $out;
    }

    /**
     * Убрать позицию из запроса (иконка корзины в превью письма) — эквивалент
     * отжатия чекбокса в списке вверху. Чистим и редактируемые поля позиции.
     */
    public function removePosition(int $cid): void
    {
        unset(
            $this->selected[$cid],
            $this->editedNames[$cid],
            $this->editedNamesEn[$cid],
            $this->editedOem[$cid],
            $this->editedQty[$cid],
            $this->editedQtyEn[$cid],
        );
        unset($this->selectedPositions, $this->oemOptions, $this->supplierOptions, $this->previewLanguages);
    }

    /**
     * Быстро добавить OEM-артикул из каталога в поле «Артикул/OEM» письма
     * (editedOem) для позиции. Идемпотентно: дубликаты не добавляем, копим через
     * запятую.
     */
    public function addOem(int $cid, string $article): void
    {
        $article = trim($article);
        if ($article === '') {
            return;
        }
        $current = trim((string) ($this->editedOem[$cid] ?? ''));
        $parts = array_values(array_filter(array_map('trim', explode(',', $current)), fn ($p) => $p !== ''));
        foreach ($parts as $p) {
            if (mb_strtolower($p) === mb_strtolower($article)) {
                return;
            }
        }
        $parts[] = $article;
        $this->editedOem[$cid] = implode(', ', $parts);
    }

    /**
     * «+» у M-артикула: поставить его в начало названия позиции в письме (русского или английского —
     * на языке поставщика). Уже стоит — ничего не делаем.
     */
    public function prependSku(int $cid, string $sku, string $model = 'editedNames'): void
    {
        $sku = trim($sku);
        if ($sku === '' || ! in_array($model, ['editedNames', 'editedNamesEn'], true)) {
            return;
        }
        $name = trim((string) ($this->{$model}[$cid] ?? ''));
        if (self::startsWithSku($name, $sku)) {
            return;
        }
        $this->{$model}[$cid] = trim($sku.' '.$name);
    }

    public static function startsWithSku(string $name, string $sku): bool
    {
        return $sku !== '' && preg_match('/^'.preg_quote($sku, '/').'(?![\p{L}\p{N}])/iu', trim($name)) === 1;
    }

    /** Развернуть / свернуть карточку товара с историей поставщиков. */
    public function toggleCard(int $cid, string $where = 't'): void
    {
        $key = ($where === 'p' ? 'p' : 't').':'.$cid;
        $this->openCards = in_array($key, $this->openCards, true)
            ? array_values(array_diff($this->openCards, [$key]))
            : [...$this->openCards, $key];
        unset($this->productCards);
    }

    public function isCardOpen(int $cid, string $where = 't'): bool
    {
        return in_array(($where === 'p' ? 'p' : 't').':'.$cid, $this->openCards, true);
    }

    /**
     * Данные развёрнутых карточек: товар каталога, последнее изменение цены,
     * история поставщиков (наши запросы + закупки из 1С). Только для
     * развёрнутых — полная карточка на каждую строку утяжелила бы страницу.
     *
     * @return array<int, array{cat: CatalogItem, pc: ?\App\Models\CatalogPriceChange, history: ?array}>
     */
    #[Computed]
    public function productCards(): array
    {
        $ids = array_values(array_unique(array_map(fn ($k) => (int) substr($k, 2), $this->openCards)));
        if ($ids === []) {
            return [];
        }
        $cats = CatalogItem::query()->whereIn('id', $ids)->get()->keyBy('id');
        $pcs = \App\Models\CatalogPriceChange::query()->whereIn('catalog_item_id', $ids)
            ->orderByDesc('changed_at')->orderByDesc('id')->get()->unique('catalog_item_id')->keyBy('catalog_item_id');
        $history = app(\App\Services\Supplier\CatalogSupplierHistoryService::class)->forCatalogIds($ids);

        $out = [];
        foreach ($ids as $id) {
            if (isset($cats[$id])) {
                $out[$id] = ['cat' => $cats[$id], 'pc' => $pcs[$id] ?? null, 'history' => $history[$id] ?? null];
            }
        }

        return $out;
    }

    /**
     * Группы поставщиков для быстрого выбора: выбрал «Китай» — отмечены все
     * поставщики группы (с e-mail), остальных можно добавить поштучно.
     *
     * @return list<array{id: int, name: string, ids: list<int>, selected: bool}>
     */
    #[Computed]
    public function supplierGroups(): array
    {
        return \App\Models\SupplierGroup::query()->with(['suppliers' => fn ($q) => $q->whereNotNull('email')->where('email', '!=', '')->notArchived()])
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(function ($g) {
                $ids = $g->suppliers->pluck('id')->map(fn ($v) => (int) $v)->all();

                return [
                    'id' => (int) $g->id,
                    'name' => (string) $g->name,
                    'ids' => $ids,
                    // Выбрана, если от каждой организации группы отмечен хоть один адрес:
                    // по умолчанию от организации отмечаем один (onePerOrganization).
                    'selected' => $ids !== [] && SupplierOrganizationService::everyOrganizationSelected($g->suppliers, $this->selectedSuppliers),
                ];
            })
            ->filter(fn ($g) => $g['ids'] !== [])
            ->values()->all();
    }

    /** Выбрать / снять всю группу поставщиков. */
    /**
     * Отметить / снять все адреса организации из списка разом: письмо уходит
     * на каждый адрес, а решает снабженец обычно про компанию целиком.
     */
    public function toggleOrganizationSuppliers(int $organizationId): void
    {
        $ids = array_values(array_map(fn ($o) => (int) $o['id'], array_filter(
            $this->supplierOptions,
            fn ($o) => ($o['org_id'] ?? null) === $organizationId,
        )));
        if ($ids === []) {
            return;
        }
        $all = array_reduce($ids, fn ($c, $id) => $c && ! empty($this->selectedSuppliers[$id]), true);
        foreach ($ids as $id) {
            if ($all) {
                unset($this->selectedSuppliers[$id]);
            } else {
                $this->selectedSuppliers[$id] = true;
            }
        }
        unset($this->supplierGroups, $this->previewLanguages);
        if (! $all) {
            $this->prefillSelectedFields();
            $this->autoTranslateIfEnglish();
        }
    }

    public function toggleSupplierGroup(int $groupId): void
    {
        $group = collect($this->supplierGroups)->firstWhere('id', $groupId);
        if ($group === null) {
            return;
        }
        if ($group['selected']) {
            foreach ($group['ids'] as $id) {
                unset($this->selectedSuppliers[$id]);
            }
        } else {
            // От организации — один адрес: уже отмеченный, подходящий под позиции
            // или чаще всех присылавший цены. Остальные адреса попадают в список
            // неотмеченными — их можно добавить вручную или «отметить всех».
            $prefer = array_merge(
                array_values(array_filter($group['ids'], fn ($id) => ! empty($this->selectedSuppliers[$id]))),
                array_map(fn ($o) => (int) $o['id'], array_filter($this->supplierOptions, fn ($o) => ! empty($o['matched']))),
            );
            // Поставщик — организация (06.11.2026): от неё — уже отмеченный адрес или представитель
            // (первый контакт «Расценка»), получателя выберет QuoteParts.
            $rows = Supplier::query()->whereIn('id', $group['ids'])->whereNotNull('email')->get();
            $pick = $rows->groupBy(fn ($s) => $s->supplier_organization_id ? 'o'.$s->supplier_organization_id : 's'.$s->id)
                ->map(fn ($g) => $g->first(fn ($s) => in_array((int) $s->id, $prefer, true))?->id ?? \App\Services\Supplier\SupplierMatchService::representatives($g)->first()?->id)
                ->filter()->map(fn ($id) => (int) $id)->values()->all();
            foreach ($pick as $id) {
                $this->selectedSuppliers[$id] = true;
                if (! in_array($id, $this->addedSupplierIds, true)) {
                    $this->addedSupplierIds[] = $id;
                }
            }
        }
        unset($this->supplierOptions, $this->supplierGroups, $this->previewLanguages);
        // В группе бывают англоязычные поставщики — названия позиций переводим сразу.
        if (! $group['selected']) {
            $this->autoTranslateIfEnglish();
        }
    }

    /** Очистить весь выбор (крестик в шапке модалки запроса). */
    public function clearSelection(): void
    {
        $this->reset(['selected', 'selectedSuppliers', 'addedSupplierIds', 'supplierSearch', 'note',
            'editedNames', 'editedNamesEn', 'editedOem', 'editedQty', 'editedQtyEn', 'rfqFiles', 'attachCatalogPhotos']);
        unset($this->supplierOptions, $this->selectedPositions, $this->oemOptions, $this->previewLanguages);
    }

    /**
     * Подобранные поставщики под выбранные позиции (по матрице каталога) +
     * добавленные вручную.
     *
     * @return array<int, array{id:int, name:string, email:?string, matched:bool, item_count:int}>
     */
    #[Computed]
    public function supplierOptions(): array
    {
        $cids = $this->selectedCids();
        if ($cids === [] && $this->addedSupplierIds === []) {
            return [];
        }

        $matcher = app(SupplierMatchService::class);
        $items = CatalogItem::query()->whereIn('id', $cids)
            ->with('equipmentCategory:id,name,synonyms')->get();

        $coverage = [];
        foreach ($items as $ci) {
            foreach ($matcher->relevantSuppliersForCatalog($ci) as $s) {
                $coverage[$s->id] = ($coverage[$s->id] ?? 0) + 1;
            }
        }

        $ids = array_values(array_unique(array_merge(array_keys($coverage), array_map('intval', $this->addedSupplierIds))));
        if ($ids === []) {
            return [];
        }
        $suppliers = Supplier::query()->whereIn('id', $ids)->get()->keyBy('id');

        $out = [];
        foreach ($ids as $id) {
            $s = $suppliers->get($id);
            if (! $s) {
                continue;
            }
            $out[] = [
                'id' => $s->id,
                'name' => (string) ($s->name ?: $s->email ?: ('#'.$s->id)),
                'email' => $s->email,
                'matched' => isset($coverage[$id]),
                'item_count' => $coverage[$id] ?? 0,
                'qp_status' => $s->quoteparts_status,
            ];
        }
        usort($out, fn ($a, $b) => $b['item_count'] <=> $a['item_count']);

        // Адреса одной организации — подряд, под её названием.
        return \App\Services\Supplier\SupplierOrganizationService::withOrganizations($out);
    }

    /**
     * Поиск поставщиков для ручного добавления: одна строка на поставщика, по клику — его
     * представитель; конкретный адрес — опцией (SupplierManualSearch).
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function searchResults(): array
    {
        return app(\App\Services\Supplier\SupplierManualSearch::class)
            ->search($this->supplierSearch, collect($this->supplierOptions())->pluck('id')->map(fn ($v) => (int) $v)->all());
    }

    public function addSupplier(int $supplierId): void
    {
        if (! in_array($supplierId, $this->addedSupplierIds, true)) {
            $this->addedSupplierIds[] = $supplierId;
        }
        $this->selectedSuppliers[$supplierId] = true;
        $this->supplierSearch = '';
        unset($this->supplierOptions);
    }

    /**
     * Перечитать подбор поставщиков — вызывается при возврате фокуса на вкладку
     * (карточку поставщика правят в соседней вкладке через ✎): подтягивает
     * изменённые имя/email/язык/матрицу без потери собранного пула и правок
     * полей письма. Язык мог смениться на en → тихий доперевод названий.
     */
    public function refreshSupplierOptions(): void
    {
        unset($this->supplierOptions, $this->previewLanguages);
        $this->autoTranslateIfEnglish();
    }

    public function send(SupplierProcurementDispatchService $dispatcher)
    {
        $user = auth()->user();
        abort_unless($user?->hasAnyRole([
            Role::Procurement->value, Role::Manager->value,
            Role::HeadOfSales->value, Role::Director->value, Role::Admin->value,
        ]), 403);

        $cids = $this->selectedCids();
        $supplierIds = array_values(array_map('intval', array_keys(array_filter($this->selectedSuppliers))));
        if ($cids === []) {
            $this->addError('send', 'Выберите хотя бы одну позицию.');

            return;
        }
        if ($supplierIds === []) {
            $this->addError('send', 'Отметьте хотя бы одного поставщика.');

            return;
        }

        $extraFiles = $this->rfqFiles($cids, $user);

        // Поставщики, принявшие приглашение QuoteParts, — через платформу; остальным письмо.
        $allSupplierIds = $supplierIds;
        $route = app(\App\Services\QuoteParts\QuotePartsRouting::class)->split($supplierIds);
        $qpMsg = $route['quoteparts'] !== [] ? $this->routeToQuoteParts($cids, $route['quoteparts'], $extraFiles) : null;
        $edits = $this->letterEdits();
        // Внесистемные — наше же письмо через QuoteParts (orders@). Не принял — не отправлено, человек видит
        // причину: письмо с ящика менеджера вместо этого три дня прятало сбой (ключи поставщиков, 06–09.11.2026).
        if ($route['offsystem'] !== []) {
            $catalog = $this->catalogInRequestOrder($cids);
            $letters = [];
            foreach (\App\Models\Supplier::query()->whereIn('id', $route['offsystem'])->get() as $s) {
                $letters[(int) $s->id] = $dispatcher->composeLetter($catalog, $s, $this->note, $edits);
            }
            $offMsg = $this->routeToQuoteParts($cids, $route['offsystem'], $extraFiles, $letters);
            $qpMsg = trim(($qpMsg ?? '').' '.$offMsg);
        }
        $supplierIds = $route['mail'];
        // Отказ QuoteParts — выбор не сбрасываем: человек видит причину и повторяет.
        if (str_contains((string) $qpMsg, 'QuoteParts не принял')) {
            $this->addError('send', $qpMsg);

            return;
        }
        if ($supplierIds === []) {
            // Всё ушло через QuoteParts: мониторинг ставим и здесь — раньше он включался только после письма
            // с нашего ящика, а с маршрутизацией через QuoteParts таких писем нет (11.11.2026).
            if ($this->monitorEnabled) {
                $days = PriceMonitor::clampInterval($this->monitorDays);
                $n = app(PriceMonitorService::class)->enable($cids, $allSupplierIds, $days, $user);
                $qpMsg .= " На мониторинг цен поставлено позиций: {$n} (перезапрос раз в {$days} дн.).";
            }
            $this->supplierRequestStatus((string) $qpMsg);
            $this->reset(['selected', 'selectedSuppliers', 'addedSupplierIds', 'supplierSearch', 'note',
                'editedNames', 'editedNamesEn', 'editedOem', 'editedQty', 'editedQtyEn', 'rfqFiles', 'attachCatalogPhotos']);
            unset($this->supplierOptions, $this->selectedPositions, $this->oemOptions, $this->previewLanguages);
            $this->afterSupplierRequestReset();

            return;
        }

        $result = $dispatcher->dispatch($cids, $supplierIds, $this->note, $user, $edits, $extraFiles);

        if (($result['error'] ?? null) === 'no_mailbox') {
            $this->addError('send', 'Нет ящика для отправки (личный или общий mail@). Обратитесь к РОПу.');

            return;
        }

        $msg = ($qpMsg ? $qpMsg.' ' : '')."Отправлено запросов поставщикам: {$result['sent']}.";
        if (($result['skipped'] ?? 0) > 0) {
            $msg .= " Пропущено (уже запрошено): {$result['skipped']}.";
        }
        if (($result['failed'] ?? 0) > 0) {
            $msg .= " Ошибок: {$result['failed']}.";
        }

        // Мониторинг включаем только если письмо реально ушло: иначе позиция
        // встанет на регулярный перезапрос, ни разу не будучи запрошенной.
        if ($this->monitorEnabled && ($result['sent'] ?? 0) > 0) {
            $days = PriceMonitor::clampInterval($this->monitorDays);
            $n = app(PriceMonitorService::class)->enable(
                $cids, $allSupplierIds, $days, $user, $result['inquiry_ids'][0] ?? null
            );
            $msg .= " На мониторинг цен поставлено позиций: {$n} (перезапрос раз в {$days} дн.).";
        }

        $this->supplierRequestStatus($msg);

        // Сброс выбора. monitorEnabled/monitorDays НЕ сбрасываем: снабженец
        // обычно ставит на мониторинг несколько пачек подряд с тем же периодом.
        $this->reset(['selected', 'selectedSuppliers', 'addedSupplierIds', 'supplierSearch', 'note',
            'editedNames', 'editedNamesEn', 'editedOem', 'editedQty', 'editedQtyEn', 'rfqFiles', 'attachCatalogPhotos']);
        unset($this->supplierOptions, $this->selectedPositions, $this->oemOptions, $this->previewLanguages);
        $this->afterSupplierRequestReset();
    }

    /**
     * Вложения запроса: загруженные файлы/фото (staging на local) + опционально фото выбранных
     * позиций из каталога. Письмо — копия в черновик каждого поставщика (как в панели
     * «Поставщики»), QuoteParts — вместе с запросом.
     *
     * @param  list<int>  $cids
     * @return array<int, array{path:string, name:string, mime:string, size:int}>
     */
    private function rfqFiles(array $cids, \App\Models\User $user): array
    {
        $this->validate(['rfqFiles.*' => 'file|max:25600']);

        return app(\App\Services\Procurement\RfqFileStager::class)->stage((array) $this->rfqFiles, $cids, $this->attachCatalogPhotos, $user);
    }

    /** Компонент сбрасывает свои вычисляемые списки после отправки или очистки. */
    protected function afterSupplierRequestReset(): void {}

    /** Итог отправки для человека: по умолчанию — всплывающее сообщение. */
    protected function supplierRequestStatus(string $message): void
    {
        $this->dispatch('toast', type: 'success', message: $message);
    }
}
