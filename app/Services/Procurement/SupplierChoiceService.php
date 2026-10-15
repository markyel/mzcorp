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
     * Из кого можно выбрать: текущие цены позиции, годные для закупки — свежий ответ каждого
     * поставщика на рассылку (за год) и действующие предложения QuoteParts (оригинал или
     * отклонение, принятое к закупке).
     *
     * @return list<array{key:string, supplier:string, price:?float, currency:?string, source:string, ref:int, variant:?string, at:?\Illuminate\Support\Carbon}>
     */
    public function currentOffers(int $catalogItemId): array
    {
        $out = [];
        $mail = \Illuminate\Support\Facades\DB::table('supplier_offers as so')
            ->join('supplier_inquiry_items as sii', 'sii.id', '=', 'so.supplier_inquiry_item_id')
            ->join('supplier_inquiries as si', 'si.id', '=', 'sii.supplier_inquiry_id')
            ->leftJoin('request_items as ri', 'ri.id', '=', 'sii.request_item_id')
            ->where(\Illuminate\Support\Facades\DB::raw('coalesce(sii.catalog_item_id, ri.catalog_item_id)'), $catalogItemId)
            ->where('so.outcome', 'quoted')->whereNotNull('so.price')->where('so.created_at', '>=', now()->subYear())
            ->orderBy('so.id')->get(['so.id', 'so.price', 'so.currency', 'so.created_at', 'si.supplier_email', 'si.supplier_name']);
        $keys = self::keysByEmail($mail->pluck('supplier_email'));
        foreach ($mail as $m) {
            $key = $keys[mb_strtolower(trim((string) $m->supplier_email))] ?? null;
            if ($key !== null) {
                $out[$key] = ['key' => $key, 'supplier' => (string) ($m->supplier_name ?: $m->supplier_email), 'price' => (float) $m->price,
                    'currency' => $m->currency, 'source' => 'email', 'ref' => (int) $m->id, 'variant' => null, 'at' => \Illuminate\Support\Carbon::parse($m->created_at)];
            }
        }
        foreach (\App\Models\QuotePartsOffer::query()->where('catalog_item_id', $catalogItemId)->where('superseded', false)
            ->whereIn('status', ['active', 'expiring'])->whereNotNull('supplier_key')->orderBy('remote_updated_at')->get() as $o) {
            if (! $o->countsAsItemPrice()) {
                continue;
            }
            $out[$o->supplier_key] = ['key' => $o->supplier_key, 'supplier' => (string) ($o->supplier_name ?: $o->supplier_key), 'price' => $o->price !== null ? (float) $o->price : null,
                'currency' => $o->currency, 'source' => 'quoteparts', 'ref' => (int) $o->id,
                'variant' => ($o->kind ?? 'original') !== 'original' ? \App\Models\QuotePartsOffer::kindLabel($o->kind) : null, 'at' => $o->remote_updated_at];
        }

        return array_values($out);
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
