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
        if ($offer->catalog_item_id === null || $offer->supplier_key === null) {
            throw new \DomainException('Предложение без позиции каталога или поставщика — выбрать нельзя.');
        }
        if ($offer->ignored_at !== null) {
            throw new \DomainException('Предложение отмечено «Игнорировать» — сначала верните его.');
        }

        DB::transaction(function () use ($offer, $by) {
            if (($offer->kind ?? 'original') !== 'original' && $offer->review_status !== QuotePartsOffer::REVIEW_ACCEPTED) {
                $this->prices->review($offer, true, $by);
            }
            $this->choices->choose((int) $offer->catalog_item_id, (string) $offer->supplier_key, [
                'supplier' => (string) ($offer->supplier_name ?: $offer->supplier_key), 'price' => $offer->price !== null ? (float) $offer->price : null,
                'currency' => $offer->currency, 'source' => 'quoteparts', 'ref' => $offer->id,
            ], $by);
            PricePosition::query()->where('catalog_item_id', $offer->catalog_item_id)->whereNotNull('exported_at')
                ->update(['exported_at' => null]);
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
    public function add(string $sku, int $catalogItemId, Collection $offers, User $by): ?string
    {
        $chosen = $this->choices->forItems([$catalogItemId])->get($catalogItemId);
        if (! ($chosen && $chosen->source === 'quoteparts' && $offers->contains('id', (int) $chosen->offer_ref))) {
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
        $offers = QuotePartsOffer::query()->with('catalogItem:id,sku,name')->whereIn('catalog_item_id', $cids)
            ->where('superseded', false)->whereIn('status', ['active', 'expiring'])->whereNull('ignored_at')->get();

        $export = $this->exports->exportOffers($offers, $by);
        // Сопроводительный файл: что покупаем здесь и у кого. Не вышел — файл для 1С всё равно отдаём.
        try {
            $export->forceFill(['companion_path' => $this->exports->writeCompanion($offers, $by)])->save();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Price export companion file failed', ['price_export_id' => $export->id, 'error' => $e->getMessage()]);
        }
        $choices = $this->choices->forItems($cids);

        DB::transaction(function () use ($positions, $finish, $export, $choices, $offers, $by) {
            foreach ($positions as $p) {
                $p->forceFill(['exported_at' => now(), 'price_export_id' => $export->id]);
                if ($finish) {
                    $c = $choices->get($p->catalog_item_id);
                    $o = $c ? $offers->firstWhere('id', (int) $c->offer_ref) : null;
                    $p->forceFill([
                        'closed_at' => now(), 'closed_by_user_id' => $by->id,
                        'in_export_at' => null, 'in_export_by_user_id' => null,
                        'closed_offer' => $c ? [
                            'offer_id' => $o?->id, 'supplier' => $c->supplier_name,
                            'price' => $c->price !== null ? (float) $c->price : null, 'currency' => $c->currency,
                        ] : null,
                    ]);
                }
                $p->save();
            }
        });

        return $export;
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
            }
        });

        return count($positions);
    }
}
