<?php

namespace App\Livewire\Procurement;

use App\Enums\Role;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

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
    use WithPagination;

    /** Статусы «не дошло до КП». */
    public const PRE_QUOTE = ['new', 'assigned', 'in_progress', 'awaiting_client_clarification'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** cid => bool — выбранные позиции для запроса. */
    public array $selected = [];

    /** supplier_id => bool — кому слать. */
    public array $selectedSuppliers = [];

    /** Доп. поставщики, добавленные вручную (вне матча). */
    public array $addedSupplierIds = [];

    public string $supplierSearch = '';

    public string $greeting = 'Здравствуйте, {поставщик}!';

    public string $greetingEn = 'Hello {поставщик},';

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

    public function mount(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole([
                Role::Procurement->value, Role::Manager->value,
                Role::HeadOfSales->value, Role::Director->value, Role::Admin->value,
            ]),
            403,
        );
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /** При выборе позиции — префилл редактируемых полей из каталога. */
    public function updatedSelected($value, $key): void
    {
        $cid = (int) $key;
        if ($value && ! isset($this->editedNames[$cid])) {
            $ci = \App\Models\CatalogItem::query()->whereKey($cid)->first(['id', 'name', 'name_en', 'brand_article']);
            if ($ci !== null) {
                $this->editedNames[$cid] = (string) ($ci->name ?? '');
                $this->editedNamesEn[$cid] = (string) ($ci->name_en ?: $ci->name ?? '');
                $this->editedOem[$cid] = (string) ($ci->brand_article ?? '');
                $this->editedQty[$cid] = $this->editedQty[$cid] ?? '';
                $this->editedQtyEn[$cid] = $this->editedQtyEn[$cid] ?? '';
            }
        }
        $this->autoTranslateIfEnglish();
    }

    public function updatedSelectedSuppliers(): void
    {
        $this->autoTranslateIfEnglish();
    }

    /* ----------------------- Превью письма по языкам ---------------------- */

    /**
     * Языковые блоки превью: по одному на каждый язык среди ОТМЕЧЕННЫХ
     * поставщиков (ru перед en). Пока поставщики не выбраны — один RU-блок.
     *
     * @return array<int, array{lang:string, label:string, suppliers:array<int,string>, greeting_model:string}>
     */
    #[Computed]
    public function previewLanguages(): array
    {
        $ids = array_values(array_map('intval', array_keys(array_filter($this->selectedSuppliers))));
        $suppliers = $ids === [] ? collect() : \App\Models\Supplier::query()->whereIn('id', $ids)->orderBy('name')->get();

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
                'suppliers' => array_map(fn ($s) => (string) ($s->name ?: $s->email ?: ('#' . $s->id)), $groups[$lang]),
                'greeting_model' => $lang === 'en' ? 'greetingEn' : 'greeting',
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

    public function translateToEnglish(\App\Services\Supplier\SupplierItemTranslator $translator): void
    {
        $this->runEnglishTranslation($translator, silent: false);
    }

    /**
     * EN-названия выбранных позиций: каталожный name_en — готовое; пустые/
     * кириллические — LLM-перевод. Транзиентный сбой LLM → оставляем как есть.
     */
    private function runEnglishTranslation(\App\Services\Supplier\SupplierItemTranslator $translator, bool $silent): void
    {
        $cids = $this->selectedCids();
        if ($cids === []) {
            return;
        }
        $items = \App\Models\CatalogItem::query()->whereIn('id', $cids)->get(['id', 'name', 'name_en']);

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
        $this->runEnglishTranslation(app(\App\Services\Supplier\SupplierItemTranslator::class), silent: true);
    }

    private function hasEnglishSupplierSelected(): bool
    {
        $ids = array_values(array_map('intval', array_keys(array_filter($this->selectedSuppliers))));
        if ($ids === []) {
            return false;
        }

        return \App\Models\Supplier::query()->whereIn('id', $ids)->where('language', 'en')->exists();
    }

    /** @return array<int, int> выбранные catalog_item_id */
    private function selectedCids(): array
    {
        return array_values(array_map('intval', array_keys(array_filter($this->selected))));
    }

    /** Выбранные позиции (для панели запроса). */
    #[Computed]
    public function selectedPositions()
    {
        $cids = $this->selectedCids();
        if ($cids === []) {
            return collect();
        }

        return \App\Models\CatalogItem::query()->whereIn('id', $cids)
            ->orderBy('sku')->get(['id', 'sku', 'name', 'brand', 'brand_article']);
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

        $matcher = app(\App\Services\Supplier\SupplierMatchService::class);
        $items = \App\Models\CatalogItem::query()->whereIn('id', $cids)
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
        $suppliers = \App\Models\Supplier::query()->whereIn('id', $ids)->get()->keyBy('id');

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

    #[Computed]
    public function searchResults()
    {
        $s = trim($this->supplierSearch);
        if (mb_strlen($s) < 2) {
            return collect();
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
        $existing = collect($this->supplierOptions())->pluck('id')->all();

        return \App\Models\Supplier::query()
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

    public function send(\App\Services\Supplier\SupplierProcurementDispatchService $dispatcher)
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

        $edits = [
            'names_ru' => $this->editedNames,
            'names_en' => $this->editedNamesEn,
            'oem' => $this->editedOem,
            'qty' => $this->editedQty,
            'qty_en' => $this->editedQtyEn,
            'greeting_ru' => $this->greeting,
            'greeting_en' => $this->greetingEn,
        ];
        $result = $dispatcher->dispatch($cids, $supplierIds, $this->note, $user, $edits);

        if (($result['error'] ?? null) === 'no_mailbox') {
            $this->addError('send', 'Нет ящика для отправки (личный или общий mail@). Обратитесь к РОПу.');

            return;
        }

        $msg = "Отправлено запросов поставщикам: {$result['sent']}.";
        if (($result['skipped'] ?? 0) > 0) {
            $msg .= " Пропущено (уже запрошено): {$result['skipped']}.";
        }
        if (($result['failed'] ?? 0) > 0) {
            $msg .= " Ошибок: {$result['failed']}.";
        }
        session()->flash('procurement_status', $msg);

        // Сброс выбора.
        $this->reset(['selected', 'selectedSuppliers', 'addedSupplierIds', 'supplierSearch', 'note',
            'editedNames', 'editedNamesEn', 'editedOem', 'editedQty', 'editedQtyEn']);
        unset($this->positions, $this->supplierOptions, $this->iqotByCatalogId, $this->selectedPositions, $this->previewLanguages);
    }

    /** Базовый запрос блокеров (сматченные stale-позиции в до-КП заявках). */
    private function baseQuery(): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('request_items')
            ->join('catalog_items', 'catalog_items.id', '=', 'request_items.catalog_item_id')
            ->join('requests', 'requests.id', '=', 'request_items.request_id')
            ->where('request_items.is_active', true)
            ->whereNotNull('request_items.catalog_item_id')
            ->where('catalog_items.is_price_actual', false)
            ->whereIn('requests.status', self::PRE_QUOTE)
            ->whereNull('requests.merged_into_id');

        $s = trim($this->search);
        if ($s !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $s) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('catalog_items.sku', 'ilike', $like)
                    ->orWhere('catalog_items.name', 'ilike', $like)
                    ->orWhere('catalog_items.brand', 'ilike', $like);
            });
        }

        return $q;
    }

    /** @return array{positions:int, requests:int} */
    #[Computed]
    public function summary(): array
    {
        return [
            'positions' => (clone $this->baseQuery())->distinct()->count('catalog_items.id'),
            'requests' => (clone $this->baseQuery())->distinct()->count('requests.id'),
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
                DB::raw('count(distinct requests.id) as req_count'),
            )
            ->groupBy('catalog_items.id', 'catalog_items.sku', 'catalog_items.name', 'catalog_items.brand', 'catalog_items.price')
            ->orderByDesc('req_count')
            ->orderBy('catalog_items.sku')
            ->get();

        $perPage = 25;
        $page = Paginator::resolveCurrentPage();
        $slice = $rows->slice(($page - 1) * $perPage, $perPage)->values();
        $cids = $slice->pluck('cid')->all();

        // Коды заблокированных заявок по позиции (первые несколько).
        $codes = [];
        if ($cids !== []) {
            $codeRows = DB::table('request_items')
                ->join('requests', 'requests.id', '=', 'request_items.request_id')
                ->where('request_items.is_active', true)
                ->whereIn('request_items.catalog_item_id', $cids)
                ->whereIn('requests.status', self::PRE_QUOTE)
                ->whereNull('requests.merged_into_id')
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
     * @return \Illuminate\Support\Collection<int, \App\Models\IqotPosition>
     */
    #[Computed]
    public function iqotByCatalogId()
    {
        $ids = collect($this->positions->items())->pluck('cid')->filter()->all();
        if ($ids === []) {
            return collect();
        }

        return \App\Models\IqotPosition::with('catalogItem:id,sku,name,brand,brand_article,brands,articles,price,price_min,is_price_actual,lead_time_days')
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
                DB::raw($cidExpr . ' as cid'),
                'supplier_inquiry_items.id as item_id',
                'supplier_inquiry_items.status as item_status',
                'supplier_inquiries.supplier_name',
                'supplier_inquiries.supplier_email',
            )
            ->get();
        if ($items->isEmpty()) {
            return [];
        }

        // Последний оффер по позиции (несколько писем → берём свежий по id).
        $latestOffer = [];
        DB::table('supplier_offers')
            ->whereIn('supplier_inquiry_item_id', $items->pluck('item_id')->all())
            ->orderBy('id')
            ->get(['supplier_inquiry_item_id', 'outcome', 'price', 'currency', 'valid_until_text', 'refusal_reason'])
            ->each(function ($o) use (&$latestOffer) {
                $latestOffer[$o->supplier_inquiry_item_id] = $o;
            });

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

        return $out;
    }

    public function render()
    {
        return view('livewire.procurement.index');
    }
}
