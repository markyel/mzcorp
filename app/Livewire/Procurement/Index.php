<?php

namespace App\Livewire\Procurement;

use App\Services\Supplier\SupplierOrganizationService;
use App\Enums\Role;
use App\Livewire\Concerns\AnswersQuotePartsSuppliers;
use App\Models\CatalogItem;
use App\Models\IqotPosition;
use App\Models\PriceMonitor;
use App\Models\QuotePartsRfq;
use App\Models\Supplier;
use App\Models\SupplierInquiry;
use App\Models\User;
use App\Services\Catalog\CatalogSearchService;
use App\Services\Procurement\PriceMonitorService;
use App\Services\Procurement\PriceRefreshCandidates;
use App\Services\QuoteParts\QuotePartsPilot;
use App\Services\QuoteParts\QuotePartsRfqService;
use App\Services\Supplier\SupplierInquiryLifecycleService;
use App\Services\Supplier\SupplierItemTranslator;
use App\Services\Supplier\SupplierMatchService;
use App\Services\Supplier\SupplierProcurementDispatchService;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

/**
 * Раздел «Снабжение» (Фаза 4). Топ M-позиций, сдерживающих выдачу КП: позиции,
 * сматченные на каталог (M-артикул) с НЕАКТУАЛЬНОЙ ценой в заявках, не дошедших
 * до КП (new/assigned/in_progress/awaiting_client_clarification), агрегированные
 * по catalog_item с числом заблокированных заявок. Из него снабженец (Фаза 4.2)
 * формирует запросы поставщикам по M-артикулу. Доступ: procurement + manager +
 * head_of_sales/director/admin.
 */
class Index extends Component
{
    use AnswersQuotePartsSuppliers;
    use WithFileUploads;
    use WithPagination;

    /** @var array загруженные файлы/фото для прикрепления к RFQ */
    public $rfqFiles = [];

    /** Приложить фото выбранных позиций из каталога (photo_url → CatalogPhotoCache). */
    public bool $attachCatalogPhotos = false;

    /** Статусы «не дошло до КП». */
    public const PRE_QUOTE = ['new', 'assigned', 'in_progress', 'awaiting_client_clarification'];

    /** Допустимые периоды (дней) для режима «за период». */
    public const PERIOD_DAYS = [7, 14, 30, 60, 90];

    /** Начальный размер окна infinite-scroll (строк). */
    public const PER_PAGE = 25;

    /** Вкладка раздела: requests — запросы и блокеры; refresh — к актуализации; monitor — мониторинг цен. */
    #[Url(as: 'tab', except: 'requests')]
    public string $tab = 'requests';

    /** Включить автоматический мониторинг цен по отправляемым позициям. */
    public bool $monitorEnabled = false;

    /** Период перезапроса цены, дней. */
    public int $monitorDays = PriceMonitor::DEFAULT_INTERVAL_DAYS;

    /**
     * Окно спроса для вкладки «К актуализации», дней. Порог «регулярно
     * спрашивают» — от 2 заявок за это окно (решение заказчика 17.09.2026).
     */
    public const REFRESH_WINDOW_DAYS = PriceRefreshCandidates::WINDOW_DAYS;

    public const REFRESH_REGULAR_MIN_REQUESTS = PriceRefreshCandidates::REGULAR_MIN_REQUESTS;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Режим списка: current — блокеры в текущих до-КП заявках; period — всё, что запрашивали за период (любой статус заявки). */
    #[Url(as: 'mode', except: 'current')]
    public string $mode = 'current';

    /** Окно режима «за период», дней. */
    #[Url(as: 'days', except: 30)]
    public int $periodDays = 30;

    /** Фильтр наличия: '' — все, 'in' — только на складе, 'out' — только без остатка. */
    #[Url(as: 'stock', except: '')]
    public string $stockFilter = '';

    /** Фильтр запросов поставщикам: '' — все, 'sent' — уже запрошены (контроль ответов), 'none' — ещё не запрошены. */
    #[Url(as: 'rfq', except: '')]
    public string $rfqFilter = '';

    /**
     * Отбор по группе поставщиков, которых по позиции уже спрашивали: id группы
     * (0 — без отбора) и режим — 'asked' спрашивали у кого-то из группы,
     * 'not' ещё ни у кого из группы.
     */
    #[Url(as: 'sgroup', except: 0)]
    public int $askedGroup = 0;

    #[Url(as: 'sgmode', except: 'asked')]
    public string $askedGroupMode = 'asked';

    /**
     * Окно infinite-scroll: сколько строк списка показывать. Растёт по loadMore()
     * при долистывании; любой сброс фильтра (resetPage override ниже) возвращает
     * в PER_PAGE. Заимствован паттерн из App\Livewire\Requests\Pool.
     */
    public int $perPage = self::PER_PAGE;

    /** cid => bool — выбранные позиции для запроса. */
    public array $selected = [];

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

    /**
     * Канал отправки: mail — письмо с нашего ящика, как раньше; quoteparts — через
     * платформу (только участникам пилота при включённом рубильнике).
     */
    public string $channel = 'mail';

    /** Срок ответа для запроса через QuoteParts. */
    public string $qpReplyBy = '';
    public function mount(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole([
                Role::Procurement->value, Role::Manager->value,
                Role::HeadOfSales->value, Role::Director->value, Role::Admin->value,
            ]),
            403,
        );
        $this->qpReplyBy = now()->addDays(7)->toDateString();    }

    #[Computed]
    public function quotePartsEnabled(): bool
    {
        return QuotePartsPilot::enabledFor(auth()->user());
    }

    /** Запросы через QuoteParts из «Снабжения» (без заявки): открытые и закрытые за 30 дней. */
    #[Computed]
    public function quotePartsRfqs(): Collection
    {
        $creatorId = $this->effectiveCreatorId();

        return QuotePartsRfq::query()->whereNull('request_id')
            ->when($creatorId !== null, fn ($q) => $q->where('created_by_user_id', $creatorId))
            ->where(fn ($q) => $q->where('status', 'open')->orWhere('created_at', '>=', now()->subDays(30)))
            ->with('createdBy:id,name')->latest('id')->limit(30)->get();
    }

    protected function quotePartsRfqForAnswer(int $id): QuotePartsRfq
    {
        return QuotePartsRfq::query()->whereNull('request_id')->findOrFail($id);
    }

    protected function afterQuotePartsAnswer(): void
    {
        unset($this->quotePartsRfqs);
    }

    public function refreshQuotePartsRfq(int $id, QuotePartsRfqService $service): void
    {
        $rfq = QuotePartsRfq::query()->whereNull('request_id')->findOrFail($id);
        try {
            $service->refresh($rfq);
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: 'QuoteParts не ответил: '.mb_substr($e->getMessage(), 0, 150));
        }
        unset($this->quotePartsRfqs);
    }

    /**
     * Отправка через QuoteParts: письмо (EN + ZH) пишет платформа по карточкам позиций,
     * поэтому правки текста письма, вложения и мониторинг цен здесь не участвуют.
     *
     * @param  list<int>  $cids
     * @param  list<int>  $supplierIds
     */
    private function sendViaQuoteParts(array $cids, array $supplierIds, QuotePartsRfqService $service): void
    {
        $quantities = [];
        foreach ($cids as $cid) {
            $raw = trim((string) ($this->editedQty[$cid] ?? ''));
            $q = preg_match('/\d+(?:[.,]\d+)?/', $raw, $m) ? (float) str_replace(',', '.', $m[0]) : 1.0;
            $quantities[$cid] = $q > 0 ? $q : 1.0;
        }

        try {
            $rfq = $service->send(null, $quantities, $supplierIds, $this->qpReplyBy ?: null, $this->note, auth()->user(), files: $this->rfqFiles($cids, auth()->user()));
        } catch (Throwable $e) {
            $this->addError('send', mb_substr($e->getMessage(), 0, 400));

            return;
        }

        session()->flash('procurement_status', 'Запрос '.$rfq->tag.' отправлен через QuoteParts. Ответы — в блоке «Запросы через QuoteParts».');
        $this->reset(['selected', 'selectedSuppliers', 'addedSupplierIds', 'supplierSearch', 'note',
            'editedNames', 'editedNamesEn', 'editedOem', 'editedQty', 'editedQtyEn', 'rfqFiles', 'attachCatalogPhotos']);
        unset($this->positions, $this->supplierOptions, $this->selectedPositions, $this->oemOptions,
            $this->previewLanguages, $this->quotePartsRfqs);
    }

    /**
     * Часть рассылки поставщикам, принявшим приглашение QuoteParts: один запрос через платформу.
     *
     * @param  list<int>  $cids
     * @param  list<int>  $supplierIds
     */
    private function routeToQuoteParts(array $cids, array $supplierIds, array $files = []): string
    {
        $quantities = [];
        foreach ($cids as $cid) {
            $raw = trim((string) ($this->editedQty[$cid] ?? ''));
            $q = preg_match('/\d+(?:[.,]\d+)?/', $raw, $m) ? (float) str_replace(',', '.', $m[0]) : 1.0;
            $quantities[$cid] = $q > 0 ? $q : 1.0;
        }
        try {
            $rfq = app(QuotePartsRfqService::class)->send(null, $quantities, $supplierIds, $this->qpReplyBy ?: null, $this->note, auth()->user(), [], routed: true, files: $files);
        } catch (Throwable $e) {
            return 'QuoteParts не принял запрос ('.mb_substr($e->getMessage(), 0, 200).') — этим поставщикам не отправлено.';
        }
        unset($this->quotePartsRfqs);

        return 'Через QuoteParts: запрос '.$rfq->tag.', поставщиков '.count($supplierIds).' (приняли приглашение).';
    }

    /** Догрузить следующую порцию списка (вызывается x-intersect sentinel'ом). */
    public function loadMore(): void
    {
        $this->perPage += self::PER_PAGE;
    }

    /**
     * Override WithPagination::resetPage — при любой смене фильтра (все
     * updating*-хуки зовут resetPage) окно infinite-scroll возвращается в
     * PER_PAGE, чтобы список не оставался «раздутым» после смены выборки.
     *
     * НЕ зовём parent::resetPage() — метод приходит из трейта WithPagination,
     * а не из родителя (Livewire\Component), поэтому parent:: его не находит и
     * падает BadMethodCall, 500. Реальная пагинация не используется (positions()
     * жёстко берёт page=1, окно = perPage), так что сброса ?page= не требуется —
     * как в App\Livewire\Requests\Pool.
     */
    public function resetPage($pageName = 'page'): void
    {
        $this->perPage = self::PER_PAGE;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingMode(): void
    {
        $this->resetPage();
    }

    public function updatingPeriodDays(): void
    {
        $this->resetPage();
    }

    public function updatingStockFilter(): void
    {
        $this->resetPage();
    }

    public function updatingRfqFilter(): void
    {
        $this->resetPage();
    }

    /* ------------------------- Режим/фильтры списка ------------------------ */

    private function isPeriodMode(): bool
    {
        return $this->mode === 'period';
    }

    private function normalizedPeriodDays(): int
    {
        return in_array($this->periodDays, self::PERIOD_DAYS, true) ? $this->periodDays : 30;
    }

    /** Условия по заявке/дате для текущего режима — общие для baseQuery и выборки кодов заявок. */
    private function applyRequestScope(Builder $q): Builder
    {
        if ($this->isPeriodMode()) {
            $q->where('request_items.created_at', '>=', now()->subDays($this->normalizedPeriodDays())->startOfDay());
        } else {
            $q->whereIn('requests.status', self::PRE_QUOTE);
        }

        return $q;
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
        preg_match_all('/(?<![\p{L}\d])[MМmм]\s?-?\s?(\d{1,5})(?!\d)/u', $this->skuList, $m);
        $wanted = [];
        foreach ($m[1] as $digits) {
            // В каталоге все артикулы — M + 5 цифр; «M5333» = M05333.
            $wanted['M'.str_pad($digits, 5, '0', STR_PAD_LEFT)] = true;
        }
        $wanted = array_keys($wanted);
        if ($wanted === []) {
            $this->skuListReport = ['added' => 0, 'already' => 0, 'missing' => [], 'empty' => true];

            return;
        }

        $found = CatalogItem::query()
            ->whereIn(\Illuminate\Support\Facades\DB::raw('upper(sku)'), $wanted)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get(['id', 'sku'])
            ->unique(fn (CatalogItem $ci) => mb_strtoupper((string) $ci->sku))
            ->keyBy(fn (CatalogItem $ci) => mb_strtoupper((string) $ci->sku));

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

        $missing = array_values(array_filter($wanted, fn ($sku) => ! $found->has($sku)));
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
        return \App\Models\SupplierGroup::query()->with(['suppliers' => fn ($q) => $q->whereNotNull('email')->where('email', '!=', '')])
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
            $pick = SupplierOrganizationService::onePerOrganization($group['ids'], $prefer);
            foreach ($group['ids'] as $id) {
                if (in_array($id, $pick, true)) {
                    $this->selectedSuppliers[$id] = true;
                }
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

    #[Computed]
    public function searchResults()
    {
        $s = trim($this->supplierSearch);
        if (mb_strlen($s) < 2) {
            return collect();
        }
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $s).'%';
        $existing = collect($this->supplierOptions())->pluck('id')->all();

        return Supplier::query()
            ->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('domain', 'ilike', $like))
            ->whereNotIn('id', $existing ?: [0])
            ->orderBy('name')->limit(8)->get(['id', 'name', 'email']);
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

        if ($this->channel === 'quoteparts') {
            abort_unless($this->quotePartsEnabled, 403);
            $this->sendViaQuoteParts($cids, $supplierIds, app(QuotePartsRfqService::class));

            return;
        }

        $extraFiles = $this->rfqFiles($cids, $user);

        // Поставщики, принявшие приглашение QuoteParts, — через платформу; остальным письмо.
        $allSupplierIds = $supplierIds;
        $route = app(\App\Services\QuoteParts\QuotePartsRouting::class)->split($supplierIds);
        $qpMsg = $route['quoteparts'] !== [] ? $this->routeToQuoteParts($cids, $route['quoteparts'], $extraFiles) : null;
        $supplierIds = $route['mail'];
        if ($supplierIds === []) {
            session()->flash('procurement_status', $qpMsg);
            $this->reset(['selected', 'selectedSuppliers', 'addedSupplierIds', 'supplierSearch', 'note',
                'editedNames', 'editedNamesEn', 'editedOem', 'editedQty', 'editedQtyEn', 'rfqFiles', 'attachCatalogPhotos']);
            unset($this->positions, $this->supplierOptions, $this->selectedPositions, $this->oemOptions, $this->previewLanguages, $this->quotePartsRfqs);

            return;
        }

        $edits = [
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

        session()->flash('procurement_status', $msg);

        // Сброс выбора. monitorEnabled/monitorDays НЕ сбрасываем: снабженец
        // обычно ставит на мониторинг несколько пачек подряд с тем же периодом.
        $this->reset(['selected', 'selectedSuppliers', 'addedSupplierIds', 'supplierSearch', 'note',
            'editedNames', 'editedNamesEn', 'editedOem', 'editedQty', 'editedQtyEn', 'rfqFiles', 'attachCatalogPhotos']);
        unset($this->positions, $this->supplierOptions, $this->iqotByCatalogId, $this->selectedPositions,
            $this->oemOptions, $this->previewLanguages, $this->refreshList, $this->monitors, $this->monitorSummary);
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
        $extraFiles = [];
        foreach ((array) $this->rfqFiles as $tmp) {
            if ($tmp === null) {
                continue;
            }
            $name = $tmp->getClientOriginalName();
            $path = sprintf('mail/procurement-staging/%d/%s', $user->id, Str::random(10).'_'.$name);
            Storage::disk('local')->put($path, $tmp->get());
            $extraFiles[] = ['path' => $path, 'name' => $name, 'mime' => $tmp->getMimeType() ?: 'application/octet-stream', 'size' => $tmp->getSize() ?: 0];
        }
        if ($this->attachCatalogPhotos) {
            $cache = app(\App\Services\Catalog\CatalogPhotoCache::class);
            $cats = CatalogItem::query()->whereIn('id', $cids)->whereNotNull('photo_url')->get(['id', 'name', 'photo_url']);
            foreach ($cats as $ci) {
                $cached = $cache->ensure((int) $ci->id);
                if ($cached === null) {
                    continue;
                }
                $bytes = Storage::disk('public')->get($cached['rel']);
                if ($bytes === null || $bytes === '') {
                    continue;
                }
                $ext = str_contains($cached['mime'], 'png') ? 'png' : (str_contains($cached['mime'], 'webp') ? 'webp' : 'jpg');
                $base = Str::slug(mb_substr((string) ($ci->name ?: 'foto'), 0, 40)) ?: 'foto';
                $name = 'foto_'.$base.'.'.$ext;
                $path = sprintf('mail/procurement-staging/%d/%s', $user->id, Str::random(10).'_'.$name);
                Storage::disk('local')->put($path, $bytes);
                $extraFiles[] = ['path' => $path, 'name' => $name, 'mime' => $cached['mime'], 'size' => strlen($bytes)];
            }
        }

        return $extraFiles;
    }

    /* ----------------------- Прайсы поставщиков (QuoteParts) ----------------------- */

    /** Открытый прайс во вкладке «Прайсы поставщиков». */
    #[Url(as: 'pl')]
    public ?int $openPriceList = null;

    /** @var array<int|string, string> строка прайса => M-артикул, если это другая наша позиция */
    public array $priceRowCode = [];

    #[Computed]
    public function quotePartsPriceLists(): array
    {
        if ($this->tab !== 'pricelists' || ! QuotePartsPilot::canConverse(auth()->user())) {
            return [];
        }
        try {
            $lists = app(\App\Services\QuoteParts\QuotePartsClient::class)->priceLists();
            $names = app(QuotePartsRfqService::class)->supplierNames([(object) ['snapshot' => ['suppliers' => array_map(fn ($l) => ['external_id' => $l['supplier'] ?? null], $lists)]]]);

            return array_map(fn ($l) => $l + ['supplier_name' => $names[$l['supplier'] ?? ''] ?? null], $lists);
        } catch (Throwable $e) {
            return [['error' => mb_substr($e->getMessage(), 0, 200)]];
        }
    }

    #[Computed]
    public function quotePartsPriceList(): ?array
    {
        if ($this->tab !== 'pricelists' || $this->openPriceList === null || ! QuotePartsPilot::canConverse(auth()->user())) {
            return null;
        }
        try {
            return app(\App\Services\QuoteParts\QuotePartsClient::class)->priceList($this->openPriceList);
        } catch (Throwable) {
            return null;
        }
    }

    public function acceptPriceRow(int $rowId, \App\Services\QuoteParts\QuotePartsPriceService $prices): void
    {
        $code = trim((string) ($this->priceRowCode[$rowId] ?? '')) ?: null;
        try {
            $prices->accept((int) $this->openPriceList, $rowId, $code ? strtoupper($code) : null, auth()->user());
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: mb_substr($e->getMessage(), 0, 300));

            return;
        }
        unset($this->quotePartsPriceList, $this->quotePartsPriceLists, $this->priceRowCode[$rowId]);
        $this->dispatch('toast', type: 'success', message: 'Цена принята — оффер записан.');
    }

    public function rejectPriceRow(int $rowId, \App\Services\QuoteParts\QuotePartsPriceService $prices): void
    {
        try {
            $prices->reject((int) $this->openPriceList, $rowId, auth()->user());
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: mb_substr($e->getMessage(), 0, 300));

            return;
        }
        unset($this->quotePartsPriceList, $this->quotePartsPriceLists);
    }

    public function setTab(string $t): void
    {
        $tabs = ['requests', 'refresh', 'offers', 'monitor', 'exports', ...(QuotePartsPilot::canConverse(auth()->user()) ? ['pricelists'] : [])];
        $this->tab = in_array($t, $tabs, true) ? $t : 'requests';
        $this->perPage = self::PER_PAGE;
    }

    /**
     * Вкладка «К актуализации»: позиции с неактуальной ценой, по которым был
     * спрос за последние REFRESH_WINDOW_DAYS дней. Две причины попадания,
     * сортировка по ним же:
     *   1 — лежит на складе и цены нет: продать можно прямо сейчас, мешает цена;
     *   2 — регулярный спрос (от REFRESH_REGULAR_MIN_REQUESTS заявок за окно).
     * Позиции, уже стоящие на мониторинге, помечаются, но из списка не убираются:
     * до следующего перезапроса цена всё равно не появится.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function refreshList(): array
    {
        // Сам пул — в PriceRefreshCandidates (его же показывает раздел «QuoteParts»).
        $rows = app(PriceRefreshCandidates::class)->list(fn (Builder $q) => $this->applyAskedGroup($q));

        $search = trim($this->search);
        if ($search === '') {
            return $rows;
        }

        return array_values(array_filter($rows,
            fn ($r) => mb_stripos($r['sku'].' '.$r['name'].' '.(string) $r['brand'], $search) !== false));
    }

    /** @return array{p1:int, p2:int, total:int, monitored:int} */
    #[Computed]
    public function refreshSummary(): array
    {
        $list = $this->refreshList;

        return [
            'p1' => count(array_filter($list, fn ($x) => $x['priority'] === 1)),
            'p2' => count(array_filter($list, fn ($x) => $x['priority'] === 2)),
            'total' => count($list),
            'monitored' => count(array_filter($list, fn ($x) => $x['monitored_until'] !== null)),
        ];
    }

    /**
     * Мониторинг цен IQOT по списку «К актуализации»: на мониторинге, с ценами, без OEM.
     *
     * @return array{on:int, priced:int, no_oem:int, enabled:bool}
     */
    #[Computed]
    public function iqotMonitoringSummary(): array
    {
        $cids = array_column($this->refreshList, 'cid');
        $rows = \App\Models\IqotMonitoringItem::query()->whereIn('catalog_item_id', $cids)->where('active', true)->get(['id', 'best_price']);
        $noOem = CatalogItem::query()->whereIn('id', $cids)->get(['id', 'sku', 'brand_article', 'articles'])
            ->filter(fn (CatalogItem $c) => $c->oemForExternal() === null)->count();

        return [
            'on' => $rows->count(),
            'priced' => $rows->whereNotNull('best_price')->count(),
            'no_oem' => $noOem,
            'enabled' => app(\App\Services\Iqot\IqotMonitoringService::class)->enabled(),
        ];
    }

    /** Вкладка «Мониторинг цен»: активные и выключенные мониторинги. */
    #[Computed]
    public function monitors()
    {
        $q = PriceMonitor::query()
            ->with(['catalogItem:id,sku,name,brand,price,stock_available,is_price_actual', 'createdBy:id,name'])
            ->orderByRaw('is_active desc, next_due_at asc nulls last');

        $s = trim($this->search);
        if ($s !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $s).'%';
            $q->whereHas('catalogItem', fn ($c) => $c->where('sku', 'ilike', $like)->orWhere('name', 'ilike', $like));
        }

        return $q->limit(300)->get();
    }

    /** @return array{active:int, due:int, stale:int} */
    #[Computed]
    public function monitorSummary(): array
    {
        $rows = PriceMonitor::query()->where('is_active', true)
            ->with('catalogItem:id,is_price_actual')->get();

        return [
            'active' => $rows->count(),
            'due' => $rows->filter(fn ($m) => $m->next_due_at !== null && $m->next_due_at->isPast())->count(),
            'stale' => $rows->filter(fn ($m) => $m->catalogItem && ! $m->catalogItem->is_price_actual)->count(),
        ];
    }

    public function disableMonitor(int $id): void
    {
        $m = PriceMonitor::find($id);
        if ($m === null) {
            return;
        }
        app(PriceMonitorService::class)->disable($m);
        unset($this->monitors, $this->monitorSummary, $this->refreshList, $this->refreshSummary);
        $this->dispatch('toast', message: 'Мониторинг выключен.', type: 'success');
    }

    public function updateMonitorInterval(int $id, int $days): void
    {
        $m = PriceMonitor::find($id);
        if ($m === null) {
            return;
        }
        app(PriceMonitorService::class)->setInterval($m, $days);
        unset($this->monitors, $this->monitorSummary);
        $this->dispatch('toast', message: 'Период обновлён: раз в '.$m->fresh()->interval_days.' дн.', type: 'success');
    }

    /** Поставить позицию мониторинга в очередь на ближайший прогон планировщика. */
    public function monitorNow(int $id): void
    {
        $m = PriceMonitor::find($id);
        if ($m === null || ! $m->is_active) {
            return;
        }
        $m->forceFill(['next_due_at' => now()->subMinute()])->save();
        unset($this->monitors, $this->monitorSummary);
        $this->dispatch('toast', message: 'Запрос уйдёт при ближайшем прогоне (раз в час).', type: 'success');
    }

    /**
     * Отбор «по позиции спрашивали поставщика из группы» (или «ещё не
     * спрашивали»). Запрос поставщику — любой: из «Снабжения» или из заявки.
     */
    private function applyAskedGroup(Builder $q): void
    {
        if ($this->askedGroup <= 0) {
            return;
        }
        $emails = \App\Models\SupplierGroup::find($this->askedGroup)?->suppliers()
            ->whereNotNull('email')->pluck('email')->map(fn ($e) => mb_strtolower(trim((string) $e)))->filter()->values()->all() ?? [];
        if ($emails === []) {
            // В группе нет адресов: «спрашивали» — ничего, «не спрашивали» — всё.
            if ($this->askedGroupMode !== 'not') {
                $q->whereRaw('false');
            }

            return;
        }
        $in = implode(',', array_fill(0, count($emails), '?'));
        $exists = 'exists (select 1 from supplier_inquiry_items sii3'
            .' join supplier_inquiries si3 on si3.id = sii3.supplier_inquiry_id'
            .' left join request_items ri3 on ri3.id = sii3.request_item_id'
            .' where coalesce(sii3.catalog_item_id, ri3.catalog_item_id) = catalog_items.id'
            ." and lower(si3.supplier_email) in ({$in}))";
        $q->whereRaw(($this->askedGroupMode === 'not' ? 'not ' : '').$exists, $emails);
    }

    /** @var array<int, array{groups: array<string, int>, other: int}> */
    private array $askedGroupsCache = [];

    /**
     * Мониторинг цен IQOT по строкам «К актуализации»: строка мониторинга (если есть) и есть ли у
     * позиции OEM — без него позицию на мониторинг не ставим.
     *
     * @param  list<int>  $cids
     * @return array<int, array{row: ?\App\Models\IqotMonitoringItem, oem: bool}>
     */
    public function iqotMonitoringFor(array $cids): array
    {
        $rows = \App\Models\IqotMonitoringItem::query()->whereIn('catalog_item_id', $cids)->get()->keyBy('catalog_item_id');
        $oem = CatalogItem::query()->whereIn('id', $cids)->get(['id', 'sku', 'brand_article', 'articles'])
            ->mapWithKeys(fn (CatalogItem $c) => [$c->id => $c->oemForExternal() !== null]);

        $out = [];
        foreach ($cids as $cid) {
            $out[$cid] = ['row' => $rows->get($cid), 'oem' => (bool) ($oem[$cid] ?? false)];
        }

        return $out;
    }

    /**
     * Из каких групп уже спрашивали поставщиков по позициям: группа → сколько
     * её поставщиков спрашивали; other — спрошенные поставщики вне групп.
     * Считается только для видимых строк списка.
     *
     * @param  list<int>  $cids
     * @return array<int, array{groups: array<string, int>, other: int}>
     */
    public function askedGroupsFor(array $cids): array
    {
        $missing = array_values(array_diff(array_map('intval', $cids), array_keys($this->askedGroupsCache)));
        if ($missing !== []) {
            $in = implode(',', array_fill(0, count($missing), '?'));
            $rows = DB::select(
                "select distinct coalesce(sii.catalog_item_id, ri.catalog_item_id) as cid, lower(si.supplier_email) as email
                   from supplier_inquiry_items sii
                   join supplier_inquiries si on si.id = sii.supplier_inquiry_id
                   left join request_items ri on ri.id = sii.request_item_id
                  where coalesce(sii.catalog_item_id, ri.catalog_item_id) in ({$in})",
                $missing,
            );
            $emails = array_values(array_unique(array_filter(array_map(fn ($r) => (string) $r->email, $rows))));
            $groupsByEmail = [];
            if ($emails !== []) {
                foreach (Supplier::query()->with('groups:id,name,sort_order')->whereIn(DB::raw('lower(email)'), $emails)->get() as $s) {
                    $groupsByEmail[mb_strtolower((string) $s->email)] = $s->groups->sortBy('sort_order')->pluck('name')->all();
                }
            }
            foreach ($missing as $cid) {
                $this->askedGroupsCache[$cid] = ['groups' => [], 'other' => 0];
            }
            foreach ($rows as $r) {
                $cid = (int) $r->cid;
                $groups = $groupsByEmail[(string) $r->email] ?? [];
                if ($groups === []) {
                    $this->askedGroupsCache[$cid]['other']++;
                }
                foreach ($groups as $g) {
                    $this->askedGroupsCache[$cid]['groups'][$g] = ($this->askedGroupsCache[$cid]['groups'][$g] ?? 0) + 1;
                }
            }
        }

        return array_intersect_key($this->askedGroupsCache, array_flip(array_map('intval', $cids)));
    }

    /** Группы для отбора в списках позиций. */
    #[Computed]
    public function groupOptions(): array
    {
        return \App\Models\SupplierGroup::query()->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    public function updatedAskedGroup(): void
    {
        $this->resetPage();
        unset($this->refreshList, $this->refreshSummary);
    }

    public function updatedAskedGroupMode(): void
    {
        $this->updatedAskedGroup();
    }

    /** Базовый запрос блокеров (сматченные stale-позиции в до-КП заявках). */
    private function baseQuery(): Builder
    {
        $q = DB::table('request_items')
            ->join('catalog_items', 'catalog_items.id', '=', 'request_items.catalog_item_id')
            ->join('requests', 'requests.id', '=', 'request_items.request_id')
            ->where('request_items.is_active', true)
            ->whereNotNull('request_items.catalog_item_id')
            ->where('catalog_items.is_active', true)
            ->where('catalog_items.is_price_actual', false)
            ->whereNull('requests.merged_into_id');
        $this->applyRequestScope($q);

        if ($this->stockFilter === 'in') {
            $q->where('catalog_items.stock_available', '>', 0);
        } elseif ($this->stockFilter === 'out') {
            $q->where(fn ($w) => $w->where('catalog_items.stock_available', '<=', 0)->orWhereNull('catalog_items.stock_available'));
        }

        // «Уже запрошены» — по позиции есть запрос поставщику любого вида:
        // позиция-центричный из «Снабжения» (sii.catalog_item_id) или
        // request-центричный из карточки заявки (sii.request_item_id → catalog).
        $rfqExists = 'exists (select 1 from supplier_inquiry_items sii'
            .' left join request_items ri2 on ri2.id = sii.request_item_id'
            .' where coalesce(sii.catalog_item_id, ri2.catalog_item_id) = catalog_items.id)';
        if ($this->rfqFilter === 'sent') {
            $q->whereRaw($rfqExists);
        } elseif ($this->rfqFilter === 'none') {
            $q->whereRaw('not '.$rfqExists);
        }
        $this->applyAskedGroup($q);

        $s = trim($this->search);
        if ($s !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $s).'%';
            $q->where(function ($w) use ($like) {
                $w->where('catalog_items.sku', 'ilike', $like)
                    ->orWhere('catalog_items.name', 'ilike', $like)
                    ->orWhere('catalog_items.brand', 'ilike', $like);
            });
        }

        return $q;
    }

    /** @return array{positions:int, requests:int, in_stock:int} */
    #[Computed]
    public function summary(): array
    {
        return [
            'positions' => (clone $this->baseQuery())->distinct()->count('catalog_items.id'),
            'requests' => (clone $this->baseQuery())->distinct()->count('requests.id'),
            'in_stock' => (clone $this->baseQuery())
                ->where('catalog_items.stock_available', '>', 0)
                ->distinct()->count('catalog_items.id'),
        ];
    }

    /**
     * Топ позиций-блокеров. Агрегируем по catalog_item, обогащаем страницу
     * кодами заявок и флагом «уже в работе» (есть pending-запрос поставщику).
     */
    #[Computed]
    public function positions(): LengthAwarePaginator
    {
        $rows = (clone $this->baseQuery())
            ->select(
                'catalog_items.id as cid',
                'catalog_items.sku',
                'catalog_items.name',
                'catalog_items.brand',
                'catalog_items.price',
                'catalog_items.stock_available',
                DB::raw('count(distinct requests.id) as req_count'),
            )
            ->groupBy('catalog_items.id', 'catalog_items.sku', 'catalog_items.name', 'catalog_items.brand', 'catalog_items.price', 'catalog_items.stock_available')
            ->orderByDesc('req_count')
            ->orderBy('catalog_items.sku')
            ->get();

        // Infinite-scroll: показываем первые perPage строк (окно растёт по loadMore),
        // всегда от начала списка. LengthAwarePaginator на странице 1 сохраняет
        // корректный hasMorePages()/total() для sentinel'а и футера.
        $perPage = $this->perPage;
        $page = 1;
        $slice = $rows->slice(0, $perPage)->values();
        $cids = $slice->pluck('cid')->all();

        // Коды заблокированных заявок по позиции (первые несколько).
        $codes = [];
        if ($cids !== []) {
            $codeRows = $this->applyRequestScope(
                DB::table('request_items')
                    ->join('requests', 'requests.id', '=', 'request_items.request_id')
                    ->where('request_items.is_active', true)
                    ->whereIn('request_items.catalog_item_id', $cids)
                    ->whereNull('requests.merged_into_id')
            )
                ->select('request_items.catalog_item_id as cid', 'requests.id', 'requests.internal_code')
                ->distinct()
                ->get();
            foreach ($codeRows->groupBy('cid') as $cid => $grp) {
                $codes[$cid] = $grp->unique('id')->take(8)
                    ->map(fn ($r) => ['id' => $r->id, 'code' => $r->internal_code])
                    ->values()->all();
            }
        }

        $enriched = $slice->map(fn ($r) => [
            'cid' => $r->cid,
            'sku' => $r->sku,
            'name' => $r->name,
            'brand' => $r->brand,
            'price' => $r->price,
            'stock' => (int) ($r->stock_available ?? 0),
            'req_count' => (int) $r->req_count,
            'codes' => $codes[$r->cid] ?? [],
        ])->all();

        return new LengthAwarePaginator(
            $enriched,
            $rows->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    /**
     * Карта catalog_item_id → IqotPosition (анализ цен конкурентов) для позиций
     * текущей страницы. Та же выборка, что в разделе «Аналитика → Позиции»
     * (analyzed + report), чтобы вывод цен был единообразным (зелёный чип +
     * раскрывающееся сравнение livewire.iqot._comparison).
     *
     * @return Collection<int, IqotPosition>
     */
    #[Computed]
    public function iqotByCatalogId()
    {
        $ids = collect($this->positions->items())->pluck('cid')->filter()->all();
        if ($ids === []) {
            return collect();
        }

        return IqotPosition::with('catalogItem:id,sku,name,brand,brand_article,brands,articles,price,price_min,is_price_actual,lead_time_days')
            ->whereIn('catalog_item_id', $ids)
            ->whereNotNull('analyzed_at')
            ->whereNotNull('report')
            ->get()
            ->keyBy('catalog_item_id');
    }

    /**
     * Ответ поставщика по каждой M-позиции страницы (Фаза 4C — «замыкаем петлю»).
     * Учитывает и позиция-центричные RFQ из «Снабжения» (supplier_inquiry_items
     * по catalog_item_id), и request-центричные с того же каталожного товара
     * (request_item → catalog_item). Состояние: quoted (есть цена) > awaiting
     * (ждём ответ) > refused (все отказали). Берём лучшую (минимальную) цену.
     *
     * @return array<int, array{state:string, pending_count:int, best_price:?float, best_currency:?string, best_supplier:?string, best_valid_until:?string, offers:array<int, array{supplier:string, outcome:string, price:?float, currency:?string, valid_until:?string, refusal:?string}>}>
     */
    #[Computed]
    public function responseByCatalogId(): array
    {
        $cids = collect($this->positions->items())->pluck('cid')->filter()->all();
        if ($cids === []) {
            return [];
        }

        $cidExpr = 'COALESCE(supplier_inquiry_items.catalog_item_id, request_items.catalog_item_id)';

        $items = DB::table('supplier_inquiry_items')
            ->join('supplier_inquiries', 'supplier_inquiries.id', '=', 'supplier_inquiry_items.supplier_inquiry_id')
            ->leftJoin('request_items', 'request_items.id', '=', 'supplier_inquiry_items.request_item_id')
            ->whereIn(DB::raw($cidExpr), $cids)
            ->select(
                DB::raw($cidExpr.' as cid'),
                'supplier_inquiry_items.id as item_id',
                'supplier_inquiry_items.status as item_status',
                'supplier_inquiries.supplier_name',
                'supplier_inquiries.supplier_email',
            )
            ->get();
        if ($items->isEmpty()) {
            return $this->withChoices($this->withQuotePartsOffers([], $cids), $cids);
        }

        // Последний оффер по позиции (несколько писем → берём свежий по id).
        $latestOffer = [];
        DB::table('supplier_offers')
            ->whereIn('supplier_inquiry_item_id', $items->pluck('item_id')->all())
            ->orderBy('id')
            ->get(['id', 'supplier_inquiry_item_id', 'outcome', 'price', 'currency', 'valid_until_text', 'refusal_reason'])
            ->each(function ($o) use (&$latestOffer) {
                $latestOffer[$o->supplier_inquiry_item_id] = $o;
            });
        // Поставщик ответа рассылки — тем же ключом, что в QuoteParts (для выбора «закупаем здесь»).
        $keys = \App\Services\Procurement\SupplierChoiceService::keysByEmail($items->pluck('supplier_email'));

        $out = [];
        foreach ($items->groupBy('cid') as $cid => $grp) {
            $offers = [];
            $pending = 0;
            $hasQuoted = false;
            $hasRefused = false;
            $best = null;

            foreach ($grp as $row) {
                $supplier = (string) ($row->supplier_name ?: $row->supplier_email ?: '—');
                $o = $latestOffer[$row->item_id] ?? null;
                if ($o === null) {
                    if ($row->item_status === 'pending') {
                        $pending++;
                    }

                    continue;
                }
                $outcome = (string) $o->outcome;
                $price = $o->price !== null ? (float) $o->price : null;
                $offers[] = [
                    'supplier' => $supplier,
                    'outcome' => $outcome,
                    'price' => $price,
                    'currency' => $o->currency,
                    'valid_until' => $o->valid_until_text,
                    'refusal' => $o->refusal_reason,
                    'key' => $keys[mb_strtolower(trim((string) $row->supplier_email))] ?? null,
                    'source' => 'email',
                    'ref' => (int) $o->id,
                    'review' => null,
                ];
                if ($outcome === 'quoted') {
                    $hasQuoted = true;
                    if ($price !== null && ($best === null || $price < $best['price'])) {
                        $best = ['price' => $price, 'currency' => $o->currency, 'supplier' => $supplier, 'valid_until' => $o->valid_until_text];
                    }
                } elseif ($outcome === 'refused') {
                    $hasRefused = true;
                }
            }

            // Нет ни цены, ни ожидания, ни отказа (напр. всё cancelled) — не показываем.
            if ($offers === [] && $pending === 0) {
                continue;
            }

            $out[(int) $cid] = [
                'state' => $hasQuoted ? 'quoted' : ($pending > 0 ? 'awaiting' : ($hasRefused ? 'refused' : 'awaiting')),
                'pending_count' => $pending,
                'best_price' => $best['price'] ?? null,
                'best_currency' => $best['currency'] ?? null,
                'best_supplier' => $best['supplier'] ?? null,
                'best_valid_until' => $best['valid_until'] ?? null,
                'offers' => $offers,
            ];
        }

        return $this->withChoices($this->withQuotePartsOffers($out, $cids), $cids);
    }

    /**
     * Офферы поставщиков из QuoteParts (ответы на запросы через платформу, прайсы) — тоже
     * ответ поставщика по позиции. Оригинал — цена позиции сразу; отклонение (аналог, копия,
     * восстановленное, замена) — на разбор: цена позиции только после «Принять к закупке».
     *
     * @param  array<int, array<string, mixed>>  $out
     * @param  list<int>  $cids
     * @return array<int, array<string, mixed>>
     */
    private function withQuotePartsOffers(array $out, array $cids): array
    {
        $qp = \App\Models\QuotePartsOffer::query()->whereIn('catalog_item_id', $cids)->where('superseded', false)
            ->whereIn('status', ['active', 'expiring'])->get()->groupBy('catalog_item_id');

        foreach ($qp as $cid => $offers) {
            $entry = $out[(int) $cid] ?? ['state' => 'awaiting', 'pending_count' => 0, 'best_price' => null, 'best_currency' => null, 'best_supplier' => null, 'best_valid_until' => null, 'offers' => []];
            $priced = false;
            $toReview = false;
            foreach ($offers as $o) {
                $supplier = (string) ($o->supplier_name ?: $o->supplier_key).' · QuoteParts'.($o->source_type === 'price_list' ? ', прайс' : '');
                // Не оригинал: вид и предложенный товар — рядом с ценой.
                $variant = ($o->kind && $o->kind !== 'original')
                    ? trim(\App\Models\QuotePartsOffer::kindLabel($o->kind).' '.trim(($o->brand ?? '').' '.($o->part_number ?? '')))
                    : null;
                $validText = $o->valid_until ? 'до '.$o->valid_until->format('d.m.Y') : null;
                $entry['offers'][] = [
                    'supplier' => $supplier,
                    'outcome' => 'quoted',
                    'price' => $o->price !== null ? (float) $o->price : null,
                    'currency' => $o->currency,
                    'valid_until' => $validText,
                    'refusal' => null,
                    'variant' => $variant,
                    'note' => $o->note,
                    'photos' => collect(range(0, max(0, (int) $o->photos - 1)))->take((int) $o->photos)->map(fn ($i) => route('quoteparts.offer-photo', [$o->remote_id, $i]))->all(),
                    'key' => $o->supplier_key,
                    'source' => 'quoteparts',
                    'ref' => (int) $o->id,
                    'review' => $o->review_status,
                ];
                if (! $o->countsAsItemPrice()) {
                    $toReview = $toReview || $o->review_status === \App\Models\QuotePartsOffer::REVIEW_PENDING;

                    continue;
                }
                $priced = true;
                if ($o->price !== null && ($entry['best_price'] === null || (float) $o->price < (float) $entry['best_price'])) {
                    $entry['best_price'] = (float) $o->price;
                    $entry['best_currency'] = $o->currency;
                    $entry['best_supplier'] = $supplier.($variant ? ' · '.$variant : '');
                    $entry['best_valid_until'] = $validText;
                }
            }
            if ($priced) {
                $entry['state'] = 'quoted';
            } elseif ($toReview && $entry['state'] !== 'quoted') {
                $entry['state'] = 'review';
            }
            $out[(int) $cid] = $entry;
        }

        // «Не сможем предложить» из кабинета QuoteParts — отказ поставщика по позиции.
        $declines = \App\Models\QuotePartsItemDecline::query()->whereIn('catalog_item_id', $cids)->inForce()->get()->groupBy('catalog_item_id');
        foreach ($declines as $cid => $rows) {
            $entry = $out[(int) $cid] ?? ['state' => 'refused', 'pending_count' => 0, 'best_price' => null, 'best_currency' => null, 'best_supplier' => null, 'best_valid_until' => null, 'offers' => []];
            foreach ($rows as $d) {
                $entry['offers'][] = [
                    'supplier' => (string) ($d->supplier_name ?: $d->supplier_key).' · QuoteParts',
                    'outcome' => 'refused',
                    'price' => null,
                    'currency' => null,
                    'valid_until' => null,
                    'refusal' => 'не поставляет'.($d->reason ? ': '.$d->reason : ''),
                    'key' => $d->supplier_key,
                    'source' => 'quoteparts',
                    'ref' => null,
                    'review' => null,
                ];
            }
            $out[(int) $cid] = $entry;
        }

        return $out;
    }

    /**
     * Выбранный поставщик позиции и его текущая цена (свежий ответ того же поставщика).
     *
     * @param  array<int, array<string, mixed>>  $out
     * @param  list<int>  $cids
     * @return array<int, array<string, mixed>>
     */
    private function withChoices(array $out, array $cids): array
    {
        foreach (app(\App\Services\Procurement\SupplierChoiceService::class)->forItems($cids) as $cid => $choice) {
            $entry = $out[(int) $cid] ?? ['state' => 'none', 'pending_count' => 0, 'best_price' => null, 'best_currency' => null, 'best_supplier' => null, 'best_valid_until' => null, 'offers' => []];
            $current = collect($entry['offers'])->filter(fn ($o) => ($o['key'] ?? null) === $choice->supplier_key && self::usable($o))->last();
            $entry['chosen'] = [
                'key' => $choice->supplier_key,
                'supplier' => $choice->supplier_name,
                'price' => $current['price'] ?? null,
                'currency' => $current['currency'] ?? null,
                'current' => $current !== null,
                'at' => $choice->chosen_at?->format('d.m.Y'),
            ];
            $out[(int) $cid] = $entry;
        }

        return $out;
    }

    /** Ответ годится как цена позиции: цена есть, это не отклонение на разборе и не отклонённое. */
    private static function usable(array $offer): bool
    {
        return ($offer['outcome'] ?? null) === 'quoted' && ! in_array($offer['review'] ?? null, [\App\Models\QuotePartsOffer::REVIEW_PENDING, \App\Models\QuotePartsOffer::REVIEW_REJECTED], true);
    }

    /** Принять отклонение (аналог, восстановленное…) к закупке по запрошенной позиции. */
    public function acceptDeviation(int $offerId, \App\Services\QuoteParts\QuotePartsPriceService $prices): void
    {
        $prices->review(\App\Models\QuotePartsOffer::query()->findOrFail($offerId), true, auth()->user());
        unset($this->responseByCatalogId);
        $this->dispatch('toast', type: 'success', message: 'Принято к закупке: цена идёт как цена позиции.');
    }

    public function rejectDeviation(int $offerId, \App\Services\QuoteParts\QuotePartsPriceService $prices): void
    {
        $prices->review(\App\Models\QuotePartsOffer::query()->findOrFail($offerId), false, auth()->user());
        unset($this->responseByCatalogId);
        $this->dispatch('toast', type: 'success', message: 'Отклонено.');
    }

    /** «Закупаем здесь»: поставщик этой позиции (выбор остаётся при следующих ценах). */
    public function chooseSupplier(int $catalogItemId, string $key, \App\Services\Procurement\SupplierChoiceService $choices): void
    {
        $offer = collect($this->responseByCatalogId[$catalogItemId]['offers'] ?? [])
            ->filter(fn ($o) => ($o['key'] ?? null) === $key && self::usable($o))->last();
        if ($offer === null) {
            $this->dispatch('toast', type: 'error', message: 'У этого поставщика нет цены, годной для закупки.');

            return;
        }
        $choices->choose($catalogItemId, $key, $offer, auth()->user());
        unset($this->responseByCatalogId);
        $this->dispatch('toast', type: 'success', message: 'Закупаем у: '.$offer['supplier'].'.');
    }

    public function clearSupplierChoice(int $catalogItemId, \App\Services\Procurement\SupplierChoiceService $choices): void
    {
        $choices->clear($catalogItemId);
        unset($this->responseByCatalogId);
    }

    // ───────── «Мои запросы поставщикам» (менеджерский раздел) ─────────

    /** Вид списка RFQ: stuck — только застрявшие (фокус), all — все открытые. */
    #[Url(as: 'rfq_view', except: 'stuck')]
    public string $rfqView = 'stuck';

    /**
     * «Глазами какого менеджера» — только для admin/РОП/директора. 0 = все
     * менеджеры. Менеджер/снабженец всегда видит свои (это поле игнорируется).
     */
    #[Url(as: 'as_user', except: 0)]
    public int $viewAsUserId = 0;

    public function updatingRfqView(): void
    {
        $this->resetPage();
    }

    public function updatingViewAsUserId(): void
    {
        unset($this->myInquiries);
    }

    /** Может ли пользователь смотреть RFQ «глазами» другого менеджера. */
    private function isPrivilegedViewer(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([
            Role::HeadOfSales->value, Role::Director->value, Role::Admin->value,
        ]);
    }

    /**
     * Чьи RFQ показывать: привилегированный — выбранного менеджера (или всех,
     * если 0/не выбран); остальные — строго свои.
     */
    private function effectiveCreatorId(): ?int
    {
        if ($this->isPrivilegedViewer()) {
            return $this->viewAsUserId > 0 ? $this->viewAsUserId : null;
        }

        return (int) auth()->id();
    }

    /**
     * Менеджеры для селектора (только у кого есть RFQ) — привилегированным.
     *
     * @return array<int, array{id:int, name:string}>
     */
    #[Computed]
    public function managerOptions(): array
    {
        if (! $this->isPrivilegedViewer()) {
            return [];
        }
        $ids = SupplierInquiry::query()
            ->whereNotNull('created_by_user_id')
            ->distinct()
            ->pluck('created_by_user_id')
            ->all();
        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->all();
    }

    /**
     * Запросы поставщикам, СОЗДАННЫЕ текущим пользователем. rfqView=stuck →
     * только «застрявшие»: open + (напоминания исчерпаны при тишине ИЛИ
     * поставщик ответил, но без оффера/отказа). См. isStuckInquiry().
     *
     * @return Collection<int, SupplierInquiry>
     */
    #[Computed]
    public function myInquiries(): Collection
    {
        $creatorId = $this->effectiveCreatorId();
        $max = (int) config('services.suppliers.reminder.max', 2);

        $q = SupplierInquiry::query()
            ->when($creatorId !== null, fn ($w) => $w->where('created_by_user_id', $creatorId))
            ->has('items');

        if ($this->rfqView === 'stuck') {
            // Фильтр застрявших в SQL (иначе грузили 200 + PHP-фильтр):
            // open И (лимит напоминаний исчерпан при тишине ИЛИ ответ без оффера).
            $q->where('status', 'open')->where(function ($w) use ($max) {
                $w->where(fn ($a) => $a->where('reminders_sent', '>=', $max)->whereDoesntHave('inboundMessages'))
                    ->orWhere(fn ($b) => $b->whereHas('inboundMessages')->whereDoesntHave('offers'));
            });
        } else {
            $q->where('status', 'open');
        }

        $rows = $q
            ->withCount(['inboundMessages as inbound_count', 'items as items_count', 'offers as offers_count'])
            ->with([
                'items:id,supplier_inquiry_id,catalog_item_id,item_name,status',
                'items.catalogItem:id,sku,name',
                'relatedRequest:id,internal_code',
                'createdBy:id,name',
            ])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        foreach ($rows as $inq) {
            $inq->is_stuck = $this->isStuckInquiry($inq, $max);
        }

        return $rows;
    }

    /**
     * «Застрял» = open, есть позиции, и напоминания больше не идут:
     *   - тишина (0 входящих) И лимит напоминаний исчерпан, ЛИБО
     *   - поставщик ответил (≥1 входящее) но не дал ни оффера, ни отказа
     *     (0 записанных offers).
     */
    private function isStuckInquiry(SupplierInquiry $inq, int $max): bool
    {
        if ($inq->status !== 'open') {
            return false;
        }
        $inbound = (int) ($inq->inbound_count ?? 0);
        $offers = (int) ($inq->offers_count ?? 0);
        if ($inbound === 0) {
            return (int) $inq->reminders_sent >= $max;
        }

        return $offers === 0;
    }

    public function closeDeclined(int $inquiryId, SupplierInquiryLifecycleService $svc): void
    {
        $inq = $this->ownedInquiry($inquiryId);
        if (! $inq) {
            return;
        }
        $svc->closeAsDeclined($inq, auth()->user());
        unset($this->myInquiries);
        $this->dispatch('toast', message: 'Запрос закрыт как отказ поставщика.', type: 'success');
    }

    public function resumeRemind(int $inquiryId, SupplierInquiryLifecycleService $svc): void
    {
        $inq = $this->ownedInquiry($inquiryId);
        if (! $inq) {
            return;
        }
        $sent = $svc->resumeAndRemind($inq, auth()->user());
        unset($this->myInquiries);
        $this->dispatch('toast',
            message: $sent ? 'Возвращён в работу — напоминание отправлено.' : 'Возвращён в работу, но напоминание не ушло (проверьте почтовый ящик).',
            type: $sent ? 'success' : 'error');
    }

    public function reRequestInquiry(int $inquiryId, SupplierInquiryLifecycleService $svc): void
    {
        $inq = $this->ownedInquiry($inquiryId);
        if (! $inq) {
            return;
        }
        $res = $svc->reRequest($inq, auth()->user());
        unset($this->myInquiries);
        if ($res['ok']) {
            $this->dispatch('toast', message: "Запрос отправлен заново (позиций: {$res['sent']}).", type: 'success');

            return;
        }
        $errs = [
            'supplier_not_in_registry' => 'поставщик не в реестре — заведите его в реестре поставщиков',
            'no_catalog_items' => 'в запросе нет каталожных позиций',
            'no_mailbox' => 'нет доступного ящика для отправки',
            'dispatch_failed' => 'отправка не удалась',
        ];
        $this->dispatch('toast', message: 'Не удалось запросить заново: '.($errs[$res['error']] ?? (string) $res['error']), type: 'error');
    }

    /** Инквайри с проверкой владельца (создатель ИЛИ привилегированная роль). */
    private function ownedInquiry(int $id): ?SupplierInquiry
    {
        $inq = SupplierInquiry::find($id);
        if (! $inq) {
            $this->dispatch('toast', message: 'Запрос не найден.', type: 'error');

            return null;
        }
        $privileged = (bool) auth()->user()?->hasAnyRole([
            Role::HeadOfSales->value, Role::Director->value, Role::Admin->value, Role::Procurement->value,
        ]);
        if ((int) $inq->created_by_user_id !== (int) auth()->id() && ! $privileged) {
            $this->dispatch('toast', message: 'Нет доступа к этому запросу.', type: 'error');

            return null;
        }

        return $inq;
    }

    public function render()
    {
        return view('livewire.procurement.index');
    }
}
