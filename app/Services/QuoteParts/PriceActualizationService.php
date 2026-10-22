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
     * @param  Collection<int, QuotePartsOffer>  $offers  действующие предложения позиции на экране
     */
    public function add(string $sku, int $catalogItemId, Collection $offers, User $by): void
    {
        $chosen = $this->choices->forItems([$catalogItemId])->get($catalogItemId);
        if (! ($chosen && $chosen->source === 'quoteparts' && $offers->contains('id', (int) $chosen->offer_ref))) {
            throw new \DomainException('Сначала выберите предложение — «Закупаем здесь».');
        }

        PricePosition::query()->updateOrCreate(['sku' => $sku], [
            'catalog_item_id' => $catalogItemId,
            'in_export_at' => now(),
            'in_export_by_user_id' => $by->id,
            'exported_at' => null,
        ]);
    }

    public function remove(string $sku): void
    {
        PricePosition::query()->where('sku', $sku)->update(['in_export_at' => null, 'in_export_by_user_id' => null, 'exported_at' => null]);
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
        $offers = QuotePartsOffer::query()->with('catalogItem:id,sku')->whereIn('catalog_item_id', $cids)
            ->where('superseded', false)->whereIn('status', ['active', 'expiring'])->whereNull('ignored_at')->get();

        $export = $this->exports->exportOffers($offers, $by);
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
}
