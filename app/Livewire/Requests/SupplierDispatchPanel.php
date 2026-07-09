<?php

namespace App\Livewire\Requests;

use App\Enums\Role;
use App\Models\Request as RequestModel;
use App\Models\RequestItem;
use App\Models\Supplier;
use App\Services\Supplier\SupplierDispatchService;
use App\Services\Supplier\SupplierMatchService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Таб «Поставщики» карточки заявки (Фаза 3.2, по образцу LazyLift SmartDispatch).
 * Список позиций (неактуальные цены выделены) → чекбоксы выбора → подобранные
 * поставщики по матрице + ручное добавление любых из реестра → превью письма
 * (каталожное название при M-SKU + OEM) + вложения (файлы заявки / с диска) →
 * рассылка. Каждому поставщику — письмо с его позициями из ящика менеджера.
 */
class SupplierDispatchPanel extends Component
{
    use WithFileUploads;

    public int $requestId;

    /** item_id => bool — позиции для запроса. */
    public array $selectedItems = [];

    /** supplier_id => bool — кому слать. */
    public array $selectedSuppliers = [];

    /** Доп. поставщики, добавленные вручную (вне матча). */
    public array $addedSupplierIds = [];

    public string $supplierSearch = '';

    /** Обращение в начале письма (рус.); {поставщик} подставляется персонально. */
    public string $greeting = 'Здравствуйте, {поставщик}!';

    /** Обращение для англоязычных поставщиков. */
    public string $greetingEn = 'Hello {поставщик},';

    /** Вступительная фраза перед таблицей позиций (рус., редактируемая). */
    public string $intro = 'Просим дать цену, наличие и срок поставки на следующие позиции:';

    public string $introEn = 'Please provide the price, availability and lead time for the following items:';

    /** Заключительная фраза после позиций (рус., редактируемая). */
    public string $closing = 'Ответьте, пожалуйста, на это письмо с ценами/наличием/сроками.';

    public string $closingEn = 'Please reply to this email with prices, availability and lead times.';

    /** item_id => отредактированное название позиции для письма (рус. версия). */
    public array $editedNames = [];

    /** item_id => название для письма на английском (каталог → name_en, иначе LLM-перевод). */
    public array $editedNamesEn = [];

    /** item_id => артикул/OEM (распознанный бывает некорректным — правится). */
    public array $editedOem = [];

    /** item_id => количество строкой (число + ед., рус.). */
    public array $editedQty = [];

    /** item_id => количество строкой для англоязычных (ед. переведена: pcs. и т.п.). */
    public array $editedQtyEn = [];

    public string $note = '';

    /** attachment_id => bool — файлы заявки для вложения. */
    public array $selectedAttachments = [];

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newFiles = [];

    public function mount(RequestModel $request): void
    {
        $this->requestId = $request->id;
        // По умолчанию: выбираем позиции с НЕАКТУАЛЬНОЙ ценой (основной кейс
        // refresh) + заполняем редактируемые названия (каталог/клиент).
        foreach ($this->items() as $it) {
            if ($it['price_stale']) {
                $this->selectedItems[$it['id']] = true;
            }
            $this->editedNames[$it['id']] = $it['name'];
            $this->editedNamesEn[$it['id']] = $it['en_name'];
            $this->editedOem[$it['id']] = (string) ($it['oem'] ?? '');
            $this->editedQty[$it['id']] = (string) ($it['qty'] ?? '');
            $this->editedQtyEn[$it['id']] = (string) ($it['qty_en'] ?? '');
        }
    }

    /* ----------------------- Уже отправленные запросы --------------------- */

    /**
     * Запросы расценки по этой заявке (кому уже отправлено + сколько позиций).
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\SupplierInquiry>
     */
    #[Computed]
    public function sentInquiries()
    {
        return \App\Models\SupplierInquiry::query()
            ->where('related_request_id', $this->requestId)
            ->withCount(['items', 'messages'])
            ->with('createdBy:id,name')
            ->orderByDesc('id')
            ->get();
    }

    /* --------------------------- Расценки (офферы) ------------------------ */

    /**
     * Сводка предложений поставщиков по позициям этой заявки (блок «Расценки»):
     * по каждой запрошенной позиции — лучшая цена + разбивка по поставщикам
     * (цена/срок | отказ | ждём).
     *
     * @return array<int, array{id:int, name:string, oem:?string, best:?float, currency:?string, offers:array<int, array<string,mixed>>, received:int}>
     */
    #[Computed]
    public function offersByPosition(): array
    {
        $items = RequestItem::query()
            ->where('request_id', $this->requestId)
            ->whereHas('supplierInquiryItems')
            ->with([
                'catalogItem:id,name',
                'supplierInquiryItems.inquiry:id,supplier_email,supplier_name',
                'supplierInquiryItems.offers',
            ])
            ->orderBy('position')->get();

        $out = [];
        foreach ($items as $it) {
            $offers = [];
            foreach ($it->supplierInquiryItems as $sii) {
                $o = $sii->offers->first();
                $offers[] = [
                    'supplier' => (string) ($sii->inquiry?->supplier_name ?: $sii->inquiry?->supplier_email ?: '—'),
                    'inquiry_id' => $sii->supplier_inquiry_id,
                    'outcome' => $o?->outcome, // quoted|refused|null(ждём)
                    'price' => $o && $o->outcome === 'quoted' ? $o->price : null,
                    'currency' => $o?->currency,
                    'lead' => $o?->valid_until_text,
                    'refusal' => $o?->refusal_reason,
                ];
            }
            $quoted = collect($offers)->where('outcome', 'quoted')->filter(fn ($x) => $x['price'] !== null);
            $best = $quoted->sortBy('price')->first();
            $out[] = [
                'id' => $it->id,
                'name' => (string) (($it->catalog_item_id ? ($it->catalogItem?->name ?: $it->parsed_name) : $it->parsed_name) ?: '—'),
                'oem' => $it->parsed_article,
                'best' => $best['price'] ?? null,
                'currency' => $best['currency'] ?? null,
                'offers' => $offers,
                'received' => collect($offers)->filter(fn ($x) => $x['outcome'] !== null)->count(),
            ];
        }

        return $out;
    }

    /* ----------------------------- Позиции -------------------------------- */

    /**
     * @return array<int, array{id:int, name:string, client_name:?string, oem:?string, brand:?string, qty:?string, has_catalog:bool, price_stale:bool}>
     */
    #[Computed]
    public function requestedItemIds(): array
    {
        return \App\Models\SupplierInquiryItem::query()
            ->whereHas('inquiry', fn ($q) => $q->where('related_request_id', $this->requestId))
            ->where('status', 'pending')
            ->pluck('request_item_id')->filter()->unique()->values()->all();
    }

    #[Computed]
    public function items(): array
    {
        $requested = $this->requestedItemIds;
        $items = RequestItem::query()
            ->where('request_id', $this->requestId)->where('is_active', true)
            ->with(['brand:id,name', 'catalogItem:id,name,name_en,is_price_actual'])
            ->orderBy('position')->get();

        $svc = app(SupplierDispatchService::class);

        return $items->map(function (RequestItem $it) use ($requested, $svc) {
            $catName = $it->catalog_item_id ? ($it->catalogItem?->name ?: null) : null;
            $catNameEn = $it->catalog_item_id ? ($it->catalogItem?->name_en ?: $catName) : null;

            return [
                'id' => $it->id,
                'name' => (string) ($catName ?: $it->parsed_name ?: '—'),
                // EN-дефолт: каталожный name_en, иначе формулировка клиента
                // (менеджер переведёт в превью English).
                'en_name' => (string) ($catNameEn ?: $it->parsed_name ?: '—'),
                'client_name' => $catName && $catName !== $it->parsed_name ? $it->parsed_name : null,
                'oem' => $svc->itemOem($it),
                'brand' => ($it->brand?->name ?: $it->parsed_brand) ?: null,
                'qty' => $svc->itemQty($it, [], 'ru'),
                'qty_en' => $svc->itemQty($it, [], 'en'),
                'has_catalog' => (bool) $it->catalog_item_id,
                'price_stale' => $it->catalog_item_id ? ($it->catalogItem && ! $it->catalogItem->is_price_actual) : false,
                'requested' => in_array($it->id, $requested, true),
                'watched' => (bool) $it->price_refresh_watched,
                'discontinued' => (bool) $it->possibly_discontinued,
            ];
        })->all();
    }

    /**
     * Менеджер подтверждает/снимает «Возможно более не поставляется» по позиции.
     * После — пересчёт цикла обновления цен (статус заявки может смениться).
     */
    public function toggleDiscontinued(int $itemId, \App\Services\Supplier\PriceRefreshReconciler $reconciler): void
    {
        $item = RequestItem::query()->where('request_id', $this->requestId)->find($itemId);
        if ($item === null) {
            return;
        }
        $item->forceFill(['possibly_discontinued' => ! $item->possibly_discontinued])->save();
        unset($this->items);

        $req = RequestModel::find($this->requestId);
        if ($req !== null) {
            $reconciler->reconcile($req);
        }
    }

    public function selectStale(): void
    {
        foreach ($this->items() as $it) {
            $this->selectedItems[$it['id']] = $it['price_stale'];
        }
        $this->autoTranslateIfEnglish();
    }

    /* --------------------------- Поставщики ------------------------------- */

    /**
     * Поставщики, от которых мы УЖЕ ждём ответ по выбранным позициям
     * (pending SupplierInquiryItem). email(lower) => число позиций.
     * Защита от повторного запроса той же позиции тому же поставщику.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function awaitingByEmail(): array
    {
        $selIds = array_values(array_map('intval', array_keys(array_filter($this->selectedItems))));
        if ($selIds === []) {
            return [];
        }

        $rows = \App\Models\SupplierInquiryItem::query()
            ->where('status', 'pending')
            ->whereIn('request_item_id', $selIds)
            ->with('inquiry:id,supplier_email')
            ->get();

        $map = []; // email => [request_item_id => true]
        foreach ($rows as $sii) {
            $email = mb_strtolower(trim((string) ($sii->inquiry?->supplier_email ?? '')));
            if ($email === '') {
                continue;
            }
            $map[$email][$sii->request_item_id] = true;
        }

        return array_map('count', $map);
    }

    /**
     * Подобранные (по выбранным позициям) + добавленные вручную.
     *
     * @return array<int, array{id:int, name:string, email:?string, matched:bool, item_count:int, already_awaiting:int}>
     */
    #[Computed]
    public function supplierOptions(): array
    {
        $matcher = app(SupplierMatchService::class);
        $selectedIds = array_keys(array_filter($this->selectedItems));
        $items = RequestItem::query()->whereIn('id', $selectedIds)
            ->with(['brand:id,name', 'kbCategory:id,name,synonyms', 'catalogItem:id,brand,equipment_category_id', 'catalogItem.equipmentCategory:id,name,synonyms'])
            ->get();

        $coverage = []; // supplier_id => count covered selected items
        foreach ($items as $it) {
            foreach ($matcher->relevantSuppliers($it) as $s) {
                $coverage[$s->id] = ($coverage[$s->id] ?? 0) + 1;
            }
        }

        $ids = array_unique(array_merge(array_keys($coverage), array_map('intval', $this->addedSupplierIds)));
        if ($ids === []) {
            return [];
        }
        $suppliers = Supplier::query()->whereIn('id', $ids)->get()->keyBy('id');
        $awaiting = $this->awaitingByEmail;

        $out = [];
        foreach ($ids as $id) {
            $s = $suppliers->get($id);
            if (! $s) {
                continue;
            }
            $out[] = [
                'id' => $s->id,
                'name' => (string) ($s->name ?: $s->email ?: ('#' . $s->id)),
                'email' => $s->email,
                'matched' => isset($coverage[$id]),
                'item_count' => $coverage[$id] ?? 0,
                'already_awaiting' => $awaiting[mb_strtolower(trim((string) $s->email))] ?? 0,
            ];
        }
        usort($out, fn ($a, $b) => $b['item_count'] <=> $a['item_count']);

        return $out;
    }

    /** Поиск поставщиков для ручного добавления (вне матча). */
    #[Computed]
    public function searchResults()
    {
        $s = trim($this->supplierSearch);
        if (mb_strlen($s) < 2) {
            return collect();
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
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
        $this->autoTranslateIfEnglish();
    }

    /* --------------- Авто-перевод письма для en-поставщиков --------------- */

    public function updatedSelectedSuppliers(): void
    {
        $this->autoTranslateIfEnglish();
    }

    public function updatedSelectedItems(): void
    {
        $this->autoTranslateIfEnglish();
    }

    /**
     * Если среди отмеченных есть англоязычный поставщик — сразу готовим
     * английскую версию названий (каталог → name_en, остальное → LLM), чтобы
     * превью показывало готовое письмо без нажатия кнопки. Тихо (без тоста),
     * переводит только ещё не переведённые (кириллица/пусто) позиции.
     */
    private function autoTranslateIfEnglish(): void
    {
        if (! $this->hasEnglishSupplierSelected()) {
            return;
        }
        $this->runEnglishTranslation(app(\App\Services\Supplier\SupplierItemTranslator::class), silent: true);
    }

    private function hasEnglishSupplierSelected(): bool
    {
        $ids = array_values(array_map('intval', array_keys(array_filter($this->selectedSuppliers))));
        if ($ids === []) {
            return false;
        }

        return Supplier::query()->whereIn('id', $ids)->where('language', 'en')->exists();
    }

    /* --------------------------- Вложения --------------------------------- */

    #[Computed]
    public function requestAttachments()
    {
        $req = RequestModel::with('emailMessage.attachments:id,email_message_id,filename,mime_type,size_bytes')->find($this->requestId);

        return $req?->emailMessage?->attachments ?? collect();
    }

    public function removeNewFile(int $idx): void
    {
        unset($this->newFiles[$idx]);
        $this->newFiles = array_values($this->newFiles);
    }

    /* --------------------------- Отправка --------------------------------- */

    public function send(SupplierDispatchService $dispatcher)
    {
        $req = RequestModel::findOrFail($this->requestId);
        $user = auth()->user();
        if ($user === null) {
            abort(403);
        }
        $privileged = $user->hasAnyRole([Role::HeadOfSales->value, Role::Director->value, Role::Admin->value]);
        if ($user->hasRole(Role::Secretary->value) || (! $privileged && ! $req->isAccessibleBy($user))) {
            abort(403, 'Доступно назначенному менеджеру, acting или РОПу.');
        }

        $itemIds = array_values(array_map('intval', array_keys(array_filter($this->selectedItems))));
        $supplierIds = array_values(array_map('intval', array_keys(array_filter($this->selectedSuppliers))));
        if ($itemIds === []) {
            $this->addError('send', 'Выберите хотя бы одну позицию.');

            return null;
        }
        if ($supplierIds === []) {
            $this->addError('send', 'Отметьте хотя бы одного поставщика.');

            return null;
        }

        $this->validate(['newFiles.*' => 'file|max:25600']);

        // Сохраняем загруженные файлы в staging на local; сервис скопирует
        // их в черновик каждого поставщика.
        $extraFiles = [];
        foreach ($this->newFiles as $tmp) {
            $name = $tmp->getClientOriginalName();
            $path = sprintf('mail/dispatch-staging/%d/%s', $this->requestId, Str::random(10) . '_' . $name);
            Storage::disk('local')->put($path, $tmp->get());
            $extraFiles[] = ['path' => $path, 'name' => $name, 'mime' => $tmp->getMimeType() ?: 'application/octet-stream', 'size' => $tmp->getSize() ?: 0];
        }

        $reqAttIds = array_values(array_map('intval', array_keys(array_filter($this->selectedAttachments))));

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
        $result = $dispatcher->dispatch($req, $supplierIds, $itemIds, $this->note, $user, $reqAttIds, $extraFiles, $edits);

        $msg = "Отправлено запросов поставщикам: {$result['sent']}.";
        if ($result['failed'] > 0) {
            $msg .= " Ошибок: {$result['failed']}.";
        }
        session()->flash('status', $msg);

        return $this->redirect(route('requests.show', ['request' => $req, 'tab' => 'suppliers']), navigate: false);
    }

    /* ------------------------ Превью письма (модалка) --------------------- */

    public bool $previewOpen = false;

    public ?int $previewSupplierId = null;

    /**
     * Открыть полное превью письма — ровно то, что уйдёт поставщику
     * (та же view emails.supplier-rfq, те же правки/обращение/язык).
     */
    public function openEmailPreview(): void
    {
        $supplierIds = array_values(array_map('intval', array_keys(array_filter($this->selectedSuppliers))));
        if (array_keys(array_filter($this->selectedItems)) === []) {
            $this->addError('send', 'Выберите хотя бы одну позицию.');

            return;
        }
        if ($supplierIds === []) {
            $this->addError('send', 'Отметьте хотя бы одного поставщика.');

            return;
        }
        $this->resetErrorBag('send');
        if (! in_array((int) $this->previewSupplierId, $supplierIds, true)) {
            $this->previewSupplierId = $supplierIds[0];
        }
        $this->previewOpen = true;
    }

    public function setPreviewSupplier(int $supplierId): void
    {
        $this->previewSupplierId = $supplierId;
    }

    public function closeEmailPreview(): void
    {
        $this->previewOpen = false;
    }

    /**
     * Табы модалки превью — отмеченные поставщики (письмо у каждого своё:
     * язык + персональное обращение).
     *
     * @return array<int, array{id:int, label:string, lang:string}>
     */
    #[Computed]
    public function previewSupplierTabs(): array
    {
        $ids = array_values(array_map('intval', array_keys(array_filter($this->selectedSuppliers))));
        if ($ids === []) {
            return [];
        }

        return Supplier::query()->whereIn('id', $ids)->orderBy('name')->get()
            ->map(fn (Supplier $s) => [
                'id' => $s->id,
                'label' => (string) ($s->name ?: $s->email ?: ('#' . $s->id)),
                'lang' => in_array($s->language, ['ru', 'en'], true) ? $s->language : 'ru',
            ])->all();
    }

    /**
     * Собранное письмо для выбранного в модалке поставщика. Использует ТОТ ЖЕ
     * код, что и dispatch(): itemRows с правками менеджера, personalGreeting,
     * view emails.supplier-rfq — превью не может разойтись с реальным письмом.
     *
     * @return array{supplier:string, to:string, lang:string, subject:string, html:string, attachments:array<int,string>}|null
     */
    #[Computed]
    public function emailPreview(): ?array
    {
        if (! $this->previewOpen || ! $this->previewSupplierId) {
            return null;
        }
        $supplier = Supplier::find($this->previewSupplierId);
        $req = RequestModel::find($this->requestId);
        if (! $supplier || ! $req) {
            return null;
        }

        $itemIds = array_values(array_map('intval', array_keys(array_filter($this->selectedItems))));
        $items = RequestItem::query()
            ->where('request_id', $this->requestId)->where('is_active', true)
            ->when($itemIds !== [], fn ($q) => $q->whereIn('id', $itemIds))
            ->with(['brand:id,name', 'catalogItem:id,name,name_en'])
            ->orderBy('position')->get();

        $svc = app(SupplierDispatchService::class);
        $lang = in_array($supplier->language, ['ru', 'en'], true) ? $supplier->language : 'ru';
        $edits = [
            'names_ru' => $this->editedNames,
            'names_en' => $this->editedNamesEn,
            'oem' => $this->editedOem,
            'qty' => $this->editedQty,
            'qty_en' => $this->editedQtyEn,
        ];
        $rows = $svc->itemRows($items, $lang, $edits);
        $greeting = $svc->personalGreeting($lang === 'en' ? $this->greetingEn : $this->greeting, $supplier, $lang);
        $subject = $lang === 'en'
            ? 'Price request — [' . $req->internal_code . ']'
            : 'Запрос расценки — [' . $req->internal_code . ']';
        $html = view('emails.supplier-rfq', [
            'request' => $req,
            'supplier' => $supplier,
            'rows' => $rows,
            'note' => trim($this->note),
            'greeting' => $greeting,
            'lang' => $lang,
            'intro' => trim($lang === 'en' ? $this->introEn : $this->intro),
            'closing' => trim($lang === 'en' ? $this->closingEn : $this->closing),
        ])->render();

        $attNames = [];
        foreach (array_keys(array_filter($this->selectedAttachments)) as $aid) {
            $a = $this->requestAttachments->firstWhere('id', (int) $aid);
            if ($a) {
                $attNames[] = (string) $a->filename;
            }
        }
        foreach ($this->newFiles as $f) {
            try {
                $attNames[] = (string) $f->getClientOriginalName();
            } catch (\Throwable) {
                // temp-файл мог протухнуть — не валим превью
            }
        }

        return [
            'supplier' => (string) ($supplier->name ?: $supplier->email),
            'to' => (string) $supplier->email,
            'lang' => $lang,
            'subject' => $subject,
            'html' => $html,
            'attachments' => $attNames,
        ];
    }

    /**
     * Языковые блоки превью: по одному на каждый язык среди ОТМЕЧЕННЫХ
     * поставщиков (ru перед en). Письмо уходит per supplier, поэтому при
     * выборе поставщиков из разных языковых групп показываем 2 превью —
     * каждое на том языке, на котором реально улетит. Пока поставщики не
     * выбраны — один RU-блок по умолчанию.
     *
     * @return array<int, array{lang:string, label:string, suppliers:array<int,string>, greeting_model:string, intro_model:string, closing_model:string}>
     */
    #[Computed]
    public function previewLanguages(): array
    {
        $ids = array_values(array_map('intval', array_keys(array_filter($this->selectedSuppliers))));
        $suppliers = $ids === [] ? collect() : Supplier::query()->whereIn('id', $ids)->orderBy('name')->get();

        /** @var array<string, array<int, Supplier>> $groups */
        $groups = [];
        foreach ($suppliers as $s) {
            $lang = in_array($s->language, ['ru', 'en'], true) ? $s->language : 'ru';
            $groups[$lang][] = $s;
        }
        if ($groups === []) {
            $groups['ru'] = []; // дефолтное превью до выбора поставщиков
        }

        $out = [];
        foreach (['ru', 'en'] as $lang) {
            if (! array_key_exists($lang, $groups)) {
                continue;
            }
            $list = $groups[$lang];
            $out[] = [
                'lang' => $lang,
                'label' => $lang === 'en' ? 'English' : 'Русский',
                'suppliers' => array_map(fn (Supplier $s) => (string) ($s->name ?: $s->email ?: ('#' . $s->id)), $list),
                'greeting_model' => $lang === 'en' ? 'greetingEn' : 'greeting',
                'intro_model' => $lang === 'en' ? 'introEn' : 'intro',
                'closing_model' => $lang === 'en' ? 'closingEn' : 'closing',
            ];
        }

        return $out;
    }

    /**
     * Строки превью на конкретном языке. Все три поля редактируемые: название
     * (RU → editedNames, EN → editedNamesEn), артикул (editedOem) и кол-во
     * (editedQty) — артикул/кол-во общие для языков. Флаг cyrillic — для
     * EN-строк, оставшихся кириллицей (не отправить русское англоязычному).
     *
     * @return array<int, array{id:int, name_model:string, cyrillic:bool}>
     */
    public function previewRowsForLang(string $lang): array
    {
        $lang = $lang === 'en' ? 'en' : 'ru';
        $ids = array_keys(array_filter($this->selectedItems));
        if ($ids === []) {
            return [];
        }
        $items = RequestItem::query()->whereIn('id', $ids)
            ->with(['catalogItem:id,name,name_en'])->orderBy('position')->get();
        $overrides = $lang === 'en' ? $this->editedNamesEn : $this->editedNames;
        $svc = app(SupplierDispatchService::class);

        $out = [];
        foreach ($items as $it) {
            $name = $svc->itemName($it, $overrides, $lang);
            $out[] = [
                'id' => $it->id,
                'name_model' => $lang === 'en' ? 'editedNamesEn' : 'editedNames',
                'qty_model' => $lang === 'en' ? 'editedQtyEn' : 'editedQty',
                'cyrillic' => $lang === 'en' && preg_match('/\p{Cyrillic}/u', $name) === 1,
            ];
        }

        return $out;
    }

    /**
     * Ручная (по кнопке) перегенерация английских названий выбранных позиций.
     */
    public function translateToEnglish(\App\Services\Supplier\SupplierItemTranslator $translator): void
    {
        $this->runEnglishTranslation($translator, silent: false);
    }

    /**
     * LLM-перевод названий выбранных позиций на английский. Каталожные позиции
     * с name_en — готовое; остальные, у которых EN-название ещё пустое или
     * осталось кириллицей, — переводим через SupplierItemTranslator. silent —
     * без тоста (для авто-перевода при выборе en-поставщика).
     */
    private function runEnglishTranslation(\App\Services\Supplier\SupplierItemTranslator $translator, bool $silent): void
    {
        $ids = array_keys(array_filter($this->selectedItems));
        if ($ids === []) {
            return;
        }

        $items = RequestItem::query()->whereIn('id', $ids)
            ->with(['catalogItem:id,name,name_en'])->orderBy('position')->get();

        $toTranslate = [];
        foreach ($items as $it) {
            $catalog = $it->catalog_item_id ? $it->catalogItem : null;
            if ($catalog && trim((string) $catalog->name_en) !== '') {
                $this->editedNamesEn[$it->id] = $catalog->name_en; // каталог — готовый перевод
                continue;
            }
            // Уже на английском (правка менеджера / прошлый перевод) — не трогаем.
            $current = trim((string) ($this->editedNamesEn[$it->id] ?? ''));
            if ($current !== '' && preg_match('/\p{Cyrillic}/u', $current) !== 1) {
                continue;
            }
            $src = trim((string) ($this->editedNames[$it->id] ?? '')) ?: (string) ($it->parsed_name ?? '');
            if ($src !== '') {
                $toTranslate[$it->id] = $src;
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
            $this->dispatch('toast', message: $missed > 0
                ? "Переведено. Не удалось: {$missed} — проверьте вручную."
                : 'Названия переведены на английский.', type: $missed > 0 ? 'warning' : 'success');
        }
    }

    public function render()
    {
        return view('livewire.requests.supplier-dispatch-panel');
    }
}
