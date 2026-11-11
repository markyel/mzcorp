<?php

namespace App\Livewire\QuoteParts;

use App\Enums\Role;
use App\Models\CatalogItem;
use App\Models\Supplier;
use App\Services\Procurement\RfqFileStager;
use App\Services\QuoteParts\QuotePartsPriceRequestService;
use App\Services\QuoteParts\QuotePartsRouting;
use App\Services\Supplier\SupplierManualSearch;
use App\Services\Supplier\SupplierMatchService;
use App\Services\Supplier\SupplierOrganizationService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * «Запросить цены» в разделе «QuoteParts» (11.11.2026): пачка позиций из «Кандидатов» или пула —
 * поставщикам через QuoteParts, количество не обязательно (актуализация цен). Подбор поставщиков —
 * как в «Снабжении»; отправка — QuotePartsPriceRequestService.
 */
class PriceRequest extends Component
{
    use WithFileUploads;

    /** Позиций в одном запросе: столько принимает QuoteParts. */
    public const MAX_ITEMS = 200;

    public bool $open = false;

    /** @var list<int> catalog_item_id */
    public array $cids = [];

    /** @var array<int|string, string> catalog_item_id => количество текстом; пусто — без количества */
    public array $qty = [];

    /** @var array<int|string, bool> supplier_id => отмечен */
    public array $selectedSuppliers = [];

    /** @var list<int> */
    public array $addedSupplierIds = [];

    public string $supplierSearch = '';

    public string $skus = '';

    public string $replyBy = '';

    public string $note = '';

    /** @var array<int|string, string> catalog_item_id => артикул для поставщика (по умолчанию — OEM каталога) */
    public array $oem = [];

    /** Фото и файлы к запросу — каждому поставщику. */
    public $rfqFiles = [];

    public bool $attachCatalogPhotos = false;

    public static function canSend(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([
            Role::Procurement->value, Role::Manager->value,
            Role::HeadOfSales->value, Role::Director->value, Role::Admin->value,
        ]);
    }

    /** @param  list<int>  $cids */
    #[On('qp-price-request')]
    public function openFor(array $cids): void
    {
        abort_unless(self::canSend(), 403);
        $this->cids = array_slice(array_values(array_unique(array_map('intval', $cids))), 0, self::MAX_ITEMS);
        $this->qty = array_intersect_key($this->qty, array_flip($this->cids));
        $this->open = true;
        $this->resetErrorBag();
        unset($this->items, $this->supplierOptions);
        $this->prefillArticles();
    }

    public function close(): void
    {
        $this->reset();
    }

    #[Computed]
    public function items()
    {
        return CatalogItem::query()->whereIn('id', $this->cids)->orderBy('sku')
            ->get(['id', 'sku', 'name', 'brand', 'brand_article', 'brands', 'articles', 'photo_url']);
    }

    /** Артикул для поставщика по умолчанию — OEM каталога без нашего M-кода, как в «Снабжении». */
    private function prefillArticles(): void
    {
        foreach ($this->items as $ci) {
            $this->oem[$ci->id] ??= (string) ($ci->oemForExternal() ?? '');
        }
    }

    /**
     * OEM-артикулы каталога по позициям (brand_article + articles[], без наших M-кодов) с брендом —
     * для подсказки «показать OEM» и быстрого добавления в поле артикула.
     *
     * @return array<int, list<array{article:string, brand:string}>>
     */
    #[Computed]
    public function oemOptions(): array
    {
        $out = [];
        foreach ($this->items as $ci) {
            $articles = array_merge([$ci->brand_article], (array) ($ci->articles ?? []));
            $brands = array_merge([$ci->brand], (array) ($ci->brands ?? []));
            $seen = [];
            $list = [];
            foreach ($articles as $idx => $art) {
                $art = trim((string) $art);
                if ($art === '' || CatalogItem::isInternalCode($art, $ci->sku) || isset($seen[mb_strtolower($art)])) {
                    continue;
                }
                $seen[mb_strtolower($art)] = true;
                $list[] = ['article' => $art, 'brand' => trim((string) ($brands[$idx] ?? ''))];
            }
            $out[$ci->id] = $list;
        }

        return $out;
    }

    /** Добавить OEM-артикул каталога в поле артикула позиции (через запятую, без повторов). */
    public function addOem(int $cid, string $article): void
    {
        $article = trim($article);
        if ($article === '') {
            return;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', (string) ($this->oem[$cid] ?? ''))), fn ($p) => $p !== ''));
        foreach ($parts as $p) {
            if (mb_strtolower($p) === mb_strtolower($article)) {
                return;
            }
        }
        $parts[] = $article;
        $this->oem[$cid] = implode(', ', $parts);
    }

    public function removeItem(int $cid): void
    {
        $this->cids = array_values(array_filter($this->cids, fn ($id) => $id !== $cid));
        unset($this->qty[$cid], $this->oem[$cid], $this->items, $this->supplierOptions, $this->oemOptions);
    }

    /** Добавить позиции списком артикулов M…: «M06374, M26773 M31866». */
    public function addSkus(): void
    {
        preg_match_all('/[MМ]\s?-?(\d{3,6})/iu', $this->skus, $m);
        $codes = array_values(array_unique(array_map(fn ($d) => 'M'.str_pad($d, 5, '0', STR_PAD_LEFT), $m[1] ?? [])));
        if ($codes === []) {
            $this->addError('skus', 'Не нашёл артикулов вида M12345.');

            return;
        }
        $found = CatalogItem::query()->whereIn('sku', $codes)->pluck('id', 'sku');
        $this->cids = array_slice(array_values(array_unique([...$this->cids, ...$found->values()->map(fn ($v) => (int) $v)->all()])), 0, self::MAX_ITEMS);
        $missing = array_values(array_diff($codes, $found->keys()->all()));
        $this->skus = '';
        $this->resetErrorBag('skus');
        if ($missing !== []) {
            $this->addError('skus', 'Нет в каталоге: '.implode(', ', $missing).'.');
        }
        unset($this->items, $this->supplierOptions, $this->oemOptions);
        $this->prefillArticles();
    }

    /**
     * Подходящие по позициям поставщики (как в «Снабжении») и добавленные вручную; адреса одной
     * организации — подряд.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function supplierOptions(): array
    {
        $matcher = app(SupplierMatchService::class);
        $coverage = [];
        foreach (CatalogItem::query()->whereIn('id', $this->cids)->with('equipmentCategory:id,name,synonyms')->get() as $ci) {
            foreach ($matcher->relevantSuppliersForCatalog($ci) as $s) {
                $coverage[$s->id] = ($coverage[$s->id] ?? 0) + 1;
            }
        }
        $ids = array_values(array_unique([...array_keys($coverage), ...array_map('intval', $this->addedSupplierIds)]));
        if ($ids === []) {
            return [];
        }
        $suppliers = Supplier::query()->whereIn('id', $ids)->get()->keyBy('id');
        $out = [];
        foreach ($ids as $id) {
            if (($s = $suppliers->get($id)) === null) {
                continue;
            }
            $out[] = [
                'id' => $s->id,
                'name' => (string) ($s->name ?: $s->email ?: ('#'.$s->id)),
                'email' => $s->email,
                'matched' => isset($coverage[$id]),
                'item_count' => $coverage[$id] ?? 0,
            ];
        }
        usort($out, fn ($a, $b) => $b['item_count'] <=> $a['item_count']);

        return SupplierOrganizationService::withOrganizations($out);
    }

    /** @return array{quoteparts: array<int, true>, offsystem: array<int, true>} */
    #[Computed]
    public function routes(): array
    {
        $ids = collect($this->supplierOptions)->pluck('id');
        $routing = app(QuotePartsRouting::class);

        return ['quoteparts' => $routing->routedIds($ids), 'offsystem' => $routing->offsystemIds($ids)];
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function searchResults(): array
    {
        return app(SupplierManualSearch::class)
            ->search($this->supplierSearch, collect($this->supplierOptions)->pluck('id')->map(fn ($v) => (int) $v)->all());
    }

    public function addSupplier(int $supplierId): void
    {
        if (! in_array($supplierId, $this->addedSupplierIds, true)) {
            $this->addedSupplierIds[] = $supplierId;
        }
        $this->selectedSuppliers[$supplierId] = true;
        $this->supplierSearch = '';
        unset($this->supplierOptions, $this->routes, $this->searchResults);
    }

    public function toggleOrganization(int $organizationId): void
    {
        $ids = array_values(array_map(fn ($o) => (int) $o['id'], array_filter($this->supplierOptions, fn ($o) => ($o['org_id'] ?? null) === $organizationId)));
        $all = $ids !== [] && array_reduce($ids, fn ($c, $id) => $c && ! empty($this->selectedSuppliers[$id]), true);
        foreach ($ids as $id) {
            if ($all) {
                unset($this->selectedSuppliers[$id]);
            } else {
                $this->selectedSuppliers[$id] = true;
            }
        }
    }

    public function send(QuotePartsPriceRequestService $service): void
    {
        abort_unless(self::canSend(), 403);
        $this->validate([
            'replyBy' => ['nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
            'rfqFiles.*' => ['file', 'max:25600'],
        ], [], ['replyBy' => 'срок ответа', 'note' => 'комментарий', 'rfqFiles.*' => 'файл']);

        $supplierIds = array_values(array_map('intval', array_keys(array_filter($this->selectedSuppliers))));
        if ($this->cids === []) {
            $this->addError('send', 'Добавьте хотя бы одну позицию.');

            return;
        }
        if ($supplierIds === []) {
            $this->addError('send', 'Отметьте хотя бы одного поставщика.');

            return;
        }

        $quantities = [];
        foreach ($this->cids as $cid) {
            $raw = trim((string) ($this->qty[$cid] ?? ''));
            $q = preg_match('/\d+(?:[.,]\d+)?/', $raw, $m) ? (float) str_replace(',', '.', $m[0]) : 0.0;
            $quantities[$cid] = $q > 0 ? $q : null;
        }

        $files = app(RfqFileStager::class)->stage((array) $this->rfqFiles, $this->cids, $this->attachCatalogPhotos, auth()->user());
        $oem = array_intersect_key(array_map(fn ($v) => (string) $v, $this->oem), array_flip($this->cids));
        $result = $service->send($quantities, $supplierIds, $this->replyBy ?: null, trim($this->note) ?: null, auth()->user(), $oem, $files);
        if ($result['tags'] === []) {
            $this->addError('send', implode(' ', $result['errors']) ?: 'Запрос не отправлен.');

            return;
        }

        $this->dispatch('toast', type: $result['errors'] === [] ? 'success' : 'warning',
            message: 'Запрос цен '.implode(', ', $result['tags']).': позиций '.count($quantities).', поставщиков '.$result['sent'].'. '
                .'Ответы — во вкладке «Актуализация цен».'.($result['errors'] !== [] ? ' '.implode(' ', $result['errors']) : ''));
        $this->dispatch('qp-price-request-sent');
        $this->reset();
    }

    public function render()
    {
        return view('livewire.quoteparts.price-request');
    }
}
