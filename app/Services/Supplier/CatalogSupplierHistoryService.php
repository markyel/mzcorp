<?php

namespace App\Services\Supplier;

use App\Models\CatalogSupplierPrice;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * История поставщиков по позиции каталога: кого и когда мы спрашивали, кто
 * дал цену или отказал, плюс первая и последняя закупка из 1С. Показывается
 * в развёрнутой карточке товара на странице снабжения и в запросе
 * поставщикам по заявке.
 *
 * Запрос поставщику ссылается на позицию двумя путями: запрос снабжения —
 * supplier_inquiry_items.catalog_item_id, запрос по заявке — через позицию
 * заявки (request_items.catalog_item_id).
 */
class CatalogSupplierHistoryService
{
    /** Сколько последних запросов по позиции показываем. */
    public const MAX_ASKED = 20;

    /**
     * @param  list<int>  $catalogIds
     * @return array<int, array{asked: list<array<string, mixed>>, onec: list<array<string, mixed>>, asked_suppliers: int, quoted_suppliers: int}>
     */
    public function forCatalogIds(array $catalogIds): array
    {
        $catalogIds = array_values(array_unique(array_filter(array_map('intval', $catalogIds))));
        if ($catalogIds === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($catalogIds), '?'));
        $asked = DB::select(
            "select coalesce(sii.catalog_item_id, ri.catalog_item_id) as cid, sii.status, si.supplier_email, si.supplier_name,
                    si.created_at, si.related_request_id, si.created_by_user_id,
                    o.outcome, o.price, o.currency, o.created_at as answered_at
               from supplier_inquiry_items sii
               join supplier_inquiries si on si.id = sii.supplier_inquiry_id
               left join request_items ri on ri.id = sii.request_item_id
               left join lateral (
                    select outcome, price, currency, created_at from supplier_offers so
                     where so.supplier_inquiry_item_id = sii.id order by so.id desc limit 1
               ) o on true
              where coalesce(sii.catalog_item_id, ri.catalog_item_id) in ({$in})
              order by si.created_at desc",
            $catalogIds,
        );

        $registry = $this->registry(array_map(fn ($r) => (string) $r->supplier_email, $asked));
        $requestCodes = DB::table('requests')->whereIn('id', array_filter(array_map(fn ($r) => $r->related_request_id, $asked)))
            ->pluck('internal_code', 'id')->all();
        $users = DB::table('users')->whereIn('id', array_filter(array_map(fn ($r) => $r->created_by_user_id, $asked)))
            ->pluck('name', 'id')->all();

        $out = [];
        foreach ($catalogIds as $id) {
            $out[$id] = ['asked' => [], 'onec' => [], 'asked_suppliers' => 0, 'quoted_suppliers' => 0];
        }

        $askedBy = [];
        $quotedBy = [];
        foreach ($asked as $r) {
            $cid = (int) $r->cid;
            $email = mb_strtolower(trim((string) $r->supplier_email));
            $supplier = $registry[$email] ?? $registry['@'.substr((string) strrchr($email, '@'), 1)] ?? null;
            $askedBy[$cid][$email] = true;
            if ($r->outcome === 'quoted') {
                $quotedBy[$cid][$email] = true;
            }
            if (count($out[$cid]['asked']) >= self::MAX_ASKED) {
                continue;
            }
            $out[$cid]['asked'][] = [
                'supplier' => $supplier?->name ?: ($r->supplier_name ?: $email),
                'email' => $email,
                'supplier_id' => $supplier?->id,
                'groups' => $supplier?->groups->pluck('name')->all() ?? [],
                'asked_at' => Carbon::parse($r->created_at),
                'status' => $r->outcome ?? ($r->status === 'pending' ? 'pending' : $r->status),
                'price' => $r->price !== null ? (float) $r->price : null,
                'currency' => $r->currency,
                'answered_at' => $r->answered_at ? Carbon::parse($r->answered_at) : null,
                'request_code' => $requestCodes[$r->related_request_id] ?? null,
                'request_id' => $r->related_request_id,
                'by' => $users[$r->created_by_user_id] ?? null,
            ];
        }
        foreach ($out as $cid => $row) {
            $out[$cid]['asked_suppliers'] = count($askedBy[$cid] ?? []);
            $out[$cid]['quoted_suppliers'] = count($quotedBy[$cid] ?? []);
        }

        foreach (CatalogSupplierPrice::query()->with('supplier.groups')->whereIn('catalog_item_id', $catalogIds)
            ->orderByRaw("case when kind = 'last' then 0 else 1 end")->get() as $p) {
            $out[(int) $p->catalog_item_id]['onec'][] = [
                'kind' => $p->kind,
                'supplier' => $p->supplier_name_1c,
                'supplier_id' => $p->supplier_id,
                'groups' => $p->supplier?->groups->pluck('name')->all() ?? [],
                'priced_at' => $p->priced_at,
                'price' => (float) $p->price,
                'currency' => $p->currency,
            ];
        }

        return $out;
    }

    /**
     * Поставщики реестра по адресам: ключ — e-mail или «@домен» для записей,
     * заведённых доменом.
     *
     * @param  list<string>  $emails
     * @return array<string, Supplier>
     */
    private function registry(array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(fn ($e) => mb_strtolower(trim($e)), $emails))));
        if ($emails === []) {
            return [];
        }
        $domains = array_values(array_unique(array_map(fn ($e) => substr((string) strrchr($e, '@'), 1), $emails)));
        $map = [];
        foreach (Supplier::query()->with('groups')
            ->where(fn ($q) => $q->whereIn(DB::raw('lower(email)'), $emails)->orWhereIn(DB::raw('lower(domain)'), $domains))
            ->get() as $s) {
            if ($s->email) {
                $map[mb_strtolower($s->email)] = $s;
            }
            if ($s->domain) {
                $map['@'.mb_strtolower($s->domain)] ??= $s;
            }
        }

        return $map;
    }
}
