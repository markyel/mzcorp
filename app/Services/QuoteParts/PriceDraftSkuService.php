<?php

namespace App\Services\QuoteParts;

use App\Models\CatalogItem;
use App\Models\CatalogItemSupplierChoice;
use App\Models\PriceDraftSku;
use App\Models\PricePosition;
use App\Models\QuotePartsOffer;
use App\Models\RequestItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * M-артикул новой позиции (13.11.2026). Позиция без M-артикула уходит в QuoteParts предварительной
 * (T-…, QuotePartsDraftService); когда снабжение узнаёт артикул, оно указывает его в «Актуализации цен»:
 *   - артикул есть в каталоге — позиция сразу привязывается к карточке (позиции заявок, предложения,
 *     выбор, список выгрузки), дальше QuotePartsDraftService::promoteMatched делает её в QuoteParts постоянной;
 *   - артикула в каталоге ещё нет — выбор и выгрузка идут по коду T-…, в файл 1С — под этим M-артикулом;
 *     как только 1С выгрузит товар, linkPending() привяжет позицию к карточке.
 * Каталог не пишем: он — копия корп. базы.
 */
class PriceDraftSkuService
{
    /** «M27410», в т.ч. набранное кириллической «М». */
    public static function normalize(string $sku): ?string
    {
        $sku = mb_strtoupper(trim(str_replace(['М', 'м'], 'M', $sku)));

        return preg_match('/^M\d{4,6}$/', $sku) === 1 ? $sku : null;
    }

    /**
     * @return array{sku: string, catalog_item_id: ?int}
     */
    public function assign(string $draftCode, string $sku, User $by): array
    {
        if (! str_starts_with($draftCode, 'T-')) {
            throw new \DomainException('M-артикул указывается только у новой позиции (T-…).');
        }
        $norm = self::normalize($sku);
        if ($norm === null) {
            throw new \DomainException('M-артикул — буква M и 4–6 цифр, например M27410.');
        }

        $row = PriceDraftSku::query()->updateOrCreate(['draft_code' => $draftCode], [
            'sku' => $norm, 'set_by_user_id' => $by->id, 'catalog_item_id' => null, 'linked_at' => null,
        ]);
        $item = CatalogItem::query()->where('sku', $norm)->first();
        if ($item !== null) {
            $this->link($row, $item);
        }
        Log::info('PriceDraftSku: assigned', ['draft' => $draftCode, 'sku' => $norm, 'catalog_item_id' => $item?->id, 'by' => $by->id]);

        return ['sku' => $norm, 'catalog_item_id' => $item?->id];
    }

    /** Снять M-артикул (ошиблись), пока позиция не привязана к карточке. */
    public function clear(string $draftCode): void
    {
        PriceDraftSku::query()->where('draft_code', $draftCode)->whereNull('catalog_item_id')->delete();
    }

    /** M-артикулы, которые за это время появились в каталоге, — к карточкам. @return int сколько привязано */
    public function linkPending(): int
    {
        $rows = PriceDraftSku::query()->whereNull('catalog_item_id')->get();
        if ($rows->isEmpty()) {
            return 0;
        }
        $items = CatalogItem::query()->whereIn('sku', $rows->pluck('sku')->unique())->get()->keyBy('sku');
        $n = 0;
        foreach ($rows as $row) {
            if (($item = $items->get($row->sku)) !== null) {
                $this->link($row, $item);
                $n++;
            }
        }

        return $n;
    }

    /** Позиция T-… — к карточке каталога: всё, что копилось по коду T-…, переходит на карточку. */
    private function link(PriceDraftSku $row, CatalogItem $item): void
    {
        $code = $row->draft_code;
        DB::transaction(function () use ($row, $item, $code) {
            // Позиции заявок: с карточкой QuotePartsDraftService::promoteMatched переведёт T-… в постоянную.
            RequestItem::query()->where('quoteparts_code', $code)->whereNull('catalog_item_id')
                ->update(['catalog_item_id' => $item->id, 'match_path' => \App\Enums\MatchPath::Manual->value]);
            QuotePartsOffer::query()->where('sku', $code)->whereNull('catalog_item_id')->update(['catalog_item_id' => $item->id]);

            // Выбор «Закупаем здесь»: у карточки свой выбор главнее.
            $draftChoice = CatalogItemSupplierChoice::query()->where('draft_code', $code)->first();
            if ($draftChoice !== null) {
                CatalogItemSupplierChoice::query()->where('catalog_item_id', $item->id)->exists()
                    ? $draftChoice->delete()
                    : $draftChoice->forceFill(['catalog_item_id' => $item->id, 'draft_code' => null])->save();
            }

            // Список выгрузки и завершённый сбор: строка T-… становится строкой M-артикула.
            $pos = PricePosition::query()->where('sku', $code)->first();
            if ($pos !== null) {
                PricePosition::query()->where('sku', $item->sku)->exists()
                    ? $pos->delete()
                    : $pos->forceFill(['sku' => $item->sku, 'catalog_item_id' => $item->id])->save();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('price_purchase_decisions')) {
                DB::table('price_purchase_decisions')->whereNull('catalog_item_id')->where('sku', $item->sku)
                    ->update(['catalog_item_id' => $item->id]);
            }

            $row->forceFill(['catalog_item_id' => $item->id, 'linked_at' => now()])->save();
        });
    }
}
