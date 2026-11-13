<?php

namespace App\Services\QuoteParts;

use App\Models\PriceExport;
use App\Models\PricePosition;
use App\Models\QuotePartsOffer;
use App\Models\User;
use App\Services\Procurement\PriceExportService;
use App\Services\Procurement\SupplierChoiceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Работа снабжения с позицией «Актуализации цен»: выбор поставщика («Закупаем здесь»), список
 * выгрузки 1С, выгрузка и завершение сбора цен.
 *
 * Два уровня: по позиции — выбрать предложение и «+ В выгрузку» (в список); выгружает — нижняя панель:
 * «Выгрузить в 1С» (позиции остаются на экране, сбор цен продолжается) или «Выгрузить и
 * завершить сбор цен» (позиция уходит с экрана и возвращается, только если придёт новое
 * предложение). Запросы QuoteParts и пул «В QuoteParts» это не трогает.
 */
class PriceActualizationService
{
    public function __construct(
        private readonly SupplierChoiceService $choices,
        private readonly QuotePartsPriceService $prices,
        private readonly PriceExportService $exports,
    ) {}

    /**
     * «Закупаем здесь». Копия или аналог, выбранные к закупке, — тем самым приняты как цена
     * позиции. Позиция, уже выгруженная, снова ждёт выгрузки: поставщик сменился.
     */
    public function choose(QuotePartsOffer $offer, User $by): void
    {
        // Новая позиция без карточки каталога (T-…) — выбор по коду, когда снабжение указало M-артикул.
        $draft = $offer->catalog_item_id === null && $offer->sku !== null
            && \App\Models\PriceDraftSku::unlinkedMap([(string) $offer->sku]) !== [] ? (string) $offer->sku : null;
        if ($offer->catalog_item_id === null && $draft === null) {
            throw new \DomainException(str_starts_with((string) $offer->sku, 'T-')
                ? 'У новой позиции нет M-артикула — укажите его, тогда можно выбрать поставщика.'
                : 'Предложение без позиции каталога — выбрать нельзя.');
        }
        if ($offer->supplier_key === null) {
            throw new \DomainException('Предложение без поставщика — выбрать нельзя.');
        }
        if ($offer->ignored_at !== null) {
            throw new \DomainException('Предложение отмечено «Игнорировать» — сначала верните его.');
        }

        DB::transaction(function () use ($offer, $by, $draft) {
            if (($offer->kind ?? 'original') !== 'original' && $offer->review_status !== QuotePartsOffer::REVIEW_ACCEPTED) {
                $this->prices->review($offer, true, $by);
            }
            $choice = [
                'supplier' => (string) ($offer->supplier_name ?: $offer->supplier_key), 'price' => $offer->price !== null ? (float) $offer->price : null,
                'currency' => $offer->currency, 'source' => 'quoteparts', 'ref' => $offer->id,
            ];
            if ($draft !== null) {
                $this->choices->chooseDraft($draft, (string) $offer->supplier_key, $choice, $by);
                PricePosition::query()->where('sku', $draft)->whereNotNull('exported_at')->update(['exported_at' => null]);

                return;
            }
            $this->choices->choose((int) $offer->catalog_item_id, (string) $offer->supplier_key, $choice, $by);
            PricePosition::query()->where('catalog_item_id', $offer->catalog_item_id)->whereNotNull('exported_at')
                ->update(['exported_at' => null]);
        });
    }

    /**
     * «Закупаем здесь» по цене из письма мимо QuoteParts (строка MailPriceOffers): выбор с источником email,
     * как в старой «Актуализации» снабжения. Позиция, уже выгруженная, снова ждёт выгрузки.
     *
     * @param  array<string, mixed>  $row
     */
    public function chooseMail(array $row, User $by): void
    {
        DB::transaction(function () use ($row, $by) {
            $this->choices->choose((int) $row['cid'], (string) $row['key'], [
                'supplier' => (string) $row['supplier'], 'price' => (float) $row['price'], 'currency' => $row['currency'],
                'source' => 'email', 'ref' => (int) $row['id'],
            ], $by);
            PricePosition::query()->where('catalog_item_id', $row['cid'])->whereNotNull('exported_at')->update(['exported_at' => null]);
        });
    }

    /**
     * «+ В выгрузку» — только когда снабженец выбрал, где закупаем: в выгрузку идут все цены
     * позиции, выбранная — с отметкой «закупаем здесь». Сами за него не выбираем.
     *
     * Список выгрузки у каждого свой: позиция — в списке того, кто её добавил, и «Выгрузить в 1С»
     * берёт только его позиции. Позиция из чужого списка переходит в мой (одна строка на артикул);
     * возвращается имя того, у кого она была, — чтобы сказать об этом.
     *
     * @param  Collection<int, QuotePartsOffer>  $offers  действующие предложения позиции на экране
     */
    public function add(string $sku, ?int $catalogItemId, Collection $offers, User $by, array $mailOfferIds = []): ?string
    {
        // Новая позиция без карточки (T-… с M-артикулом) — выбор по коду позиции.
        $key = \App\Services\Procurement\SupplierChoiceService::itemKey($catalogItemId, $sku);
        $chosen = $key === null ? null : $this->choices->forKeys([$key])->get($key);
        $picked = $chosen && (($chosen->source === 'quoteparts' && $offers->contains('id', (int) $chosen->offer_ref))
            || ($chosen->source === 'email' && in_array((int) $chosen->offer_ref, array_map('intval', $mailOfferIds), true)));
        if (! $picked) {
            throw new \DomainException('Сначала выберите предложение — «Закупаем здесь».');
        }

        $was = PricePosition::query()->with('inExportBy:id,name')->where('sku', $sku)->first();
        $from = $was && $was->in_export_at !== null && (int) $was->in_export_by_user_id !== (int) $by->id ? $was->inExportBy?->name : null;

        PricePosition::query()->updateOrCreate(['sku' => $sku], [
            'catalog_item_id' => $catalogItemId,
            'in_export_at' => now(),
            'in_export_by_user_id' => $by->id,
            'exported_at' => null,
        ]);

        return $from;
    }

    /** Убрать из СВОЕГО списка выгрузки; чужой список не трогаем. */
    public function remove(string $sku, User $by): void
    {
        PricePosition::query()->where('sku', $sku)->where('in_export_by_user_id', $by->id)
            ->update(['in_export_at' => null, 'in_export_by_user_id' => null, 'exported_at' => null]);
    }

    /**
     * Список выгрузки снабженца: позиции, которые добавил он.
     *
     * @return Collection<int, PricePosition>
     */
    public function listOf(User $by): Collection
    {
        // В списке бывает и завершённая позиция: вернулась с новым предложением и выгружается снова.
        return PricePosition::query()->whereNotNull('in_export_at')->where('in_export_by_user_id', $by->id)->get();
    }

    /**
     * Выгрузить позиции списка в 1С. $finish — и завершить сбор цен: позиции уходят с экрана
     * (closed_at), в заметке остаётся, кого и почём выгрузили.
     *
     * @param  Collection<int, PricePosition>  $positions
     */
    public function export(Collection $positions, bool $finish, User $by): PriceExport
    {
        $cids = $positions->pluck('catalog_item_id')->filter()->unique()->values()->all();
        // Новые позиции без карточки (T-…): предложения — по коду, в файл — под указанным M-артикулом.
        $draftSkus = \App\Models\PriceDraftSku::unlinkedMap($positions->whereNull('catalog_item_id')->pluck('sku'));
        $offers = QuotePartsOffer::query()->with('catalogItem:id,sku,name')
            ->where(fn ($q) => $q->whereIn('catalog_item_id', $cids)
                ->orWhere(fn ($d) => $d->whereNull('catalog_item_id')->whereIn('sku', array_keys($draftSkus))))
            ->where('superseded', false)->whereIn('status', ['active', 'expiring'])->whereNull('ignored_at')->get();

        // Цены из писем мимо QuoteParts по этим позициям — в тот же файл (12.11.2026).
        $mail = collect(app(MailPriceOffers::class)->bySku())->only($positions->pluck('sku')->all())->flatMap(fn ($m) => $m['offers'])->values();

        $export = $this->exports->exportOffers($offers, $by, $mail, $draftSkus);
        // Сопроводительный файл: что покупаем здесь и у кого. Не вышел — файл для 1С всё равно отдаём.
        try {
            $export->forceFill(['companion_path' => $this->exports->writeCompanion($offers, $by, $mail, $draftSkus)])->save();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Price export companion file failed', ['price_export_id' => $export->id, 'error' => $e->getMessage()]);
        }
        $choices = $this->choices->forKeys($positions->map(fn (PricePosition $p) => SupplierChoiceService::itemKey($p->catalog_item_id, $p->sku)));

        DB::transaction(function () use ($positions, $finish, $export, $choices, $offers, $by, $draftSkus) {
            foreach ($positions as $p) {
                $p->forceFill(['exported_at' => now(), 'price_export_id' => $export->id]);
                if ($finish) {
                    $c = $choices->get(SupplierChoiceService::itemKey($p->catalog_item_id, $p->sku));
                    $o = $c ? $offers->firstWhere('id', (int) $c->offer_ref) : null;
                    $p->forceFill([
                        'closed_at' => now(), 'closed_by_user_id' => $by->id,
                        'in_export_at' => null, 'in_export_by_user_id' => null,
                        'closed_offer' => $c ? [
                            'offer_id' => $o?->id, 'supplier' => $c->supplier_name,
                            'price' => $c->price !== null ? (float) $c->price : null, 'currency' => $c->currency,
                        ] : null,
                    ]);
                    self::recordDecision($p->catalog_item_id !== null ? (int) $p->catalog_item_id : null, (string) ($draftSkus[$p->sku] ?? $p->sku), $c, $export, $by);
                }
                $p->save();
            }
        });

        return $export;
    }

    /**
     * Закупочное решение (12.11.2026): цена «Закупаем здесь», выгруженная в 1С с завершением сбора цен, — в журнал.
     * Следующая цена позиции сравнивается с ним («Результаты» раздела QuoteParts).
     */
    public static function recordDecision(?int $catalogItemId, string $sku, ?object $choice, ?PriceExport $export, User $by): void
    {
        if ($choice === null || $choice->price === null || (float) $choice->price <= 0
            || ! \Illuminate\Support\Facades\Schema::hasTable('price_purchase_decisions')) {
            return;
        }
        DB::table('price_purchase_decisions')->insert([
            'catalog_item_id' => $catalogItemId, 'sku' => $sku,
            'supplier_key' => $choice->supplier_key, 'supplier_name' => $choice->supplier_name,
            'price' => (float) $choice->price, 'currency' => $choice->currency,
            'source' => $choice->source, 'offer_ref' => $choice->offer_ref !== null ? (int) $choice->offer_ref : null,
            'price_export_id' => $export?->id, 'decided_by_user_id' => $by->id,
            'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Завершить сбор цен по позициям из заявки (вкладка «Поставщики»): позиции уходят из
     * «Актуализации цен», как после «Выгрузить и завершить», и вернутся с новым предложением.
     * В самой заявке цены остаются. $export — если перед этим выгрузили в 1С.
     *
     * @param  array<int, string>  $positions  catalog_item_id => артикул
     */
    public function finish(array $positions, User $by, ?PriceExport $export = null): int
    {
        $choices = $this->choices->forItems(array_keys($positions));

        DB::transaction(function () use ($positions, $choices, $by, $export) {
            foreach ($positions as $cid => $sku) {
                $c = $choices->get($cid);
                PricePosition::query()->updateOrCreate(['sku' => $sku], [
                    'catalog_item_id' => $cid,
                    'in_export_at' => null,
                    'in_export_by_user_id' => null,
                    'closed_at' => now(),
                    'closed_by_user_id' => $by->id,
                    'closed_offer' => $c ? [
                        'offer_id' => $c->source === 'quoteparts' ? (int) $c->offer_ref : null, 'supplier' => $c->supplier_name,
                        'price' => $c->price !== null ? (float) $c->price : null, 'currency' => $c->currency,
                    ] : null,
                ] + ($export ? ['exported_at' => now(), 'price_export_id' => $export->id] : []));
                // Завершили с выгрузкой в 1С — закупочное решение; без выгрузки цена в 1С не ушла.
                if ($export !== null) {
                    self::recordDecision((int) $cid, (string) $sku, $c, $export, $by);
                }
            }
        });

        return count($positions);
    }

    /**
     * «Вернуть в работу»: сбор цен по позиции снова открыт — она возвращается в «Готово» / «Ждём» со всеми
     * предложениями (12.11.2026, M23123: завершили на OSS, а цену YuanQi на аналог хотели пересмотреть).
     * Отметка о прошлой выгрузке (exported_at, closed_offer) остаётся.
     */
    public function reopen(string $sku, User $by): bool
    {
        $pos = PricePosition::query()->where('sku', $sku)->whereNotNull('closed_at')->first();
        if ($pos === null) {
            return false;
        }
        \Illuminate\Support\Facades\Log::info('Price position reopened', ['sku' => $sku, 'closed_at' => (string) $pos->closed_at, 'closed_by' => $pos->closed_by_user_id, 'by' => $by->id]);
        $pos->forceFill(['closed_at' => null, 'closed_by_user_id' => null])->save();

        return true;
    }
}
