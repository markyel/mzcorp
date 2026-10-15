<?php

namespace App\Services\Procurement;

use App\Models\CatalogItemSupplierChoice;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * У кого закупаем позицию: снабжение выбирает одного из ответивших поставщиков. Выбор
 * хранится и переживает следующие обновления цен — новые цены того же поставщика просто
 * становятся его текущей ценой; поменять или снять выбор можно только руками.
 *
 * Поставщик опознаётся ключом, общим для рассылки и QuoteParts: org:{организация} /
 * sup:{адрес из справочника}; адрес без карточки — email:{адрес}.
 */
class SupplierChoiceService
{
    /**
     * @param  array{supplier:string, price:?float, currency:?string, source:?string, ref:?int}  $offer
     */
    public function choose(int $catalogItemId, string $key, array $offer, User $by): CatalogItemSupplierChoice
    {
        return CatalogItemSupplierChoice::query()->updateOrCreate(['catalog_item_id' => $catalogItemId], [
            'supplier_key' => $key,
            'supplier_name' => mb_substr($offer['supplier'], 0, 255),
            'source' => $offer['source'] ?? null,
            'offer_ref' => $offer['ref'] ?? null,
            'price' => $offer['price'] ?? null,
            'currency' => $offer['currency'] ?? null,
            'chosen_by_user_id' => $by->id,
            'chosen_at' => now(),
        ]);
    }

    public function clear(int $catalogItemId): void
    {
        CatalogItemSupplierChoice::query()->where('catalog_item_id', $catalogItemId)->delete();
    }

    /**
     * @param  list<int>  $catalogItemIds
     * @return Collection<int, CatalogItemSupplierChoice> catalog_item_id => выбор
     */
    public function forItems(array $catalogItemIds): Collection
    {
        return CatalogItemSupplierChoice::query()->whereIn('catalog_item_id', $catalogItemIds)->get()->keyBy('catalog_item_id');
    }

    /**
     * Ключи поставщиков по адресам писем (ответы старой рассылки).
     *
     * @param  iterable<string>  $emails
     * @return array<string, string> email (в нижнем регистре) => ключ
     */
    public static function keysByEmail(iterable $emails): array
    {
        $emails = collect($emails)->filter()->map(fn ($e) => mb_strtolower(trim((string) $e)))->unique()->values();
        $known = Supplier::query()->whereIn(\Illuminate\Support\Facades\DB::raw('lower(email)'), $emails)->get(['id', 'email', 'supplier_organization_id'])
            ->mapWithKeys(fn (Supplier $s) => [mb_strtolower($s->email) => $s->quotepartsKey()]);

        return $emails->mapWithKeys(fn ($e) => [$e => $known[$e] ?? 'email:'.$e])->all();
    }
}
