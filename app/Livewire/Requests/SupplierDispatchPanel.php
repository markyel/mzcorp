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

    /** Обращение в начале письма; {поставщик} подставляется персонально. */
    public string $greeting = 'Здравствуйте, {поставщик}!';

    /** item_id => отредактированное название позиции для письма. */
    public array $editedNames = [];

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
            ->with(['brand:id,name', 'catalogItem:id,name,is_price_actual'])
            ->orderBy('position')->get();

        return $items->map(function (RequestItem $it) use ($requested) {
            $catName = $it->catalog_item_id ? ($it->catalogItem?->name ?: null) : null;

            return [
                'id' => $it->id,
                'name' => (string) ($catName ?: $it->parsed_name ?: '—'),
                'client_name' => $catName && $catName !== $it->parsed_name ? $it->parsed_name : null,
                'oem' => $it->parsed_article ?: null,
                'brand' => ($it->brand?->name ?: $it->parsed_brand) ?: null,
                'qty' => $it->parsed_qty ? trim($it->parsed_qty . ' ' . ($it->parsed_unit ?: 'шт.')) : null,
                'has_catalog' => (bool) $it->catalog_item_id,
                'price_stale' => $it->catalog_item_id ? ($it->catalogItem && ! $it->catalogItem->is_price_actual) : false,
                'requested' => in_array($it->id, $requested, true),
            ];
        })->all();
    }

    public function selectStale(): void
    {
        foreach ($this->items() as $it) {
            $this->selectedItems[$it['id']] = $it['price_stale'];
        }
    }

    /* --------------------------- Поставщики ------------------------------- */

    /**
     * Подобранные (по выбранным позициям) + добавленные вручную.
     *
     * @return array<int, array{id:int, name:string, email:?string, matched:bool, item_count:int}>
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

        $result = $dispatcher->dispatch($req, $supplierIds, $itemIds, $this->note, $user, $reqAttIds, $extraFiles, $this->editedNames, $this->greeting);

        $msg = "Отправлено запросов поставщикам: {$result['sent']}.";
        if ($result['failed'] > 0) {
            $msg .= " Ошибок: {$result['failed']}.";
        }
        session()->flash('status', $msg);

        return $this->redirect(route('requests.show', ['request' => $req, 'tab' => 'suppliers']), navigate: false);
    }

    /**
     * Строки превью письма по выбранным позициям.
     *
     * @return array<int, array{name:string, oem:?string, brand:?string, qty:?string}>
     */
    #[Computed]
    public function previewRows(): array
    {
        $ids = array_keys(array_filter($this->selectedItems));
        if ($ids === []) {
            return [];
        }
        $items = RequestItem::query()->whereIn('id', $ids)
            ->with(['brand:id,name', 'catalogItem:id,name'])->orderBy('position')->get();

        return app(SupplierDispatchService::class)->itemRows($items, $this->editedNames);
    }

    /**
     * Англоязычные поставщики среди отмеченных — им письмо уйдёт на EN,
     * каталожные позиции по name_en. Для подсказки менеджеру в превью.
     *
     * @return array<int, string> имена поставщиков с language=en
     */
    #[Computed]
    public function englishSuppliers(): array
    {
        $ids = array_values(array_map('intval', array_keys(array_filter($this->selectedSuppliers))));
        if ($ids === []) {
            return [];
        }

        return Supplier::query()->whereIn('id', $ids)->where('language', 'en')
            ->orderBy('name')->get()
            ->map(fn (Supplier $s) => (string) ($s->name ?: $s->email ?: ('#' . $s->id)))
            ->all();
    }

    public function render()
    {
        return view('livewire.requests.supplier-dispatch-panel');
    }
}
