<?php

namespace App\Services\QuoteParts;

use App\Services\Iqot\IqotCurrencyConverter;
use App\Services\Procurement\PriceExportService;
use App\Services\Procurement\SupplierChoiceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Цены из писем — запросы поставщикам обычным письмом мимо QuoteParts (06–09.11.2026, пока QuoteParts
 * не принимал запросы, и письма, которые менеджеры пишут поставщикам сами) — для «Актуализации цен»
 * (12.11.2026: EVE дала 27 USD на M15073, а в «Актуализации» её не было). Ответ разобран старым
 * разборщиком (supplier_offers): цена или отказ по позиции запроса.
 */
class MailPriceOffers
{
    /**
     * По артикулу: кого спросили, последняя цена и отказ каждого поставщика.
     *
     * @return array<string, array{asked: list<string>, offered: list<string>, declined: list<string>, requested_at: ?Carbon,
     *     offers: Collection<int, array<string, mixed>>, declines: Collection<int, array<string, mixed>>}>
     */
    public function bySku(?Carbon $since = null): array
    {
        $since ??= now()->subDays(PriceRefreshReadiness::WINDOW_DAYS);
        $items = DB::table('supplier_inquiry_items as sii')
            ->join('supplier_inquiries as si', 'si.id', '=', 'sii.supplier_inquiry_id')
            ->leftJoin('request_items as ri', 'ri.id', '=', 'sii.request_item_id')
            ->join('catalog_items as ci', 'ci.id', '=', DB::raw('coalesce(sii.catalog_item_id, ri.catalog_item_id)'))
            ->leftJoin('requests as r', 'r.id', '=', 'si.related_request_id')
            ->where('si.created_at', '>=', $since)
            ->get(['sii.id', 'ci.id as cid', 'ci.sku', 'ci.name', 'si.supplier_email', 'si.supplier_name', 'si.created_at', 'si.related_request_id', 'r.internal_code']);
        if ($items->isEmpty()) {
            return [];
        }
        $keys = SupplierChoiceService::keysByEmail($items->pluck('supplier_email'));
        // Название — организации поставщика (в запросе часто только адрес: «eve.candice@evelevator.com»).
        $orgNames = DB::table('supplier_organizations')->whereIn('qp_key', array_values($keys))->pluck('name', 'qp_key');
        $offers = DB::table('supplier_offers as so')->leftJoin('email_messages as em', 'em.id', '=', 'so.email_message_id')
            ->whereIn('so.supplier_inquiry_item_id', $items->pluck('id'))
            ->orderBy('so.id')->get(['so.id', 'so.supplier_inquiry_item_id', 'so.email_message_id', 'so.outcome', 'so.price', 'so.currency',
                'so.valid_until_text', 'so.refusal_reason', 'so.raw_quote', DB::raw('coalesce(em.sent_at, so.created_at) as at')])
            ->groupBy('supplier_inquiry_item_id');

        $out = [];
        foreach ($items as $it) {
            $key = $keys[mb_strtolower(trim((string) $it->supplier_email))];
            $sku = (string) $it->sku;
            $out[$sku] ??= ['asked' => [], 'offered' => [], 'declined' => [], 'requested_at' => null, 'offers' => [], 'declines' => []];
            $out[$sku]['asked'][$key] = true;
            $at = Carbon::parse($it->created_at);
            $out[$sku]['requested_at'] = $out[$sku]['requested_at'] === null || $at->lt($out[$sku]['requested_at']) ? $at : $out[$sku]['requested_at'];
            $supplier = (string) ($orgNames[$key] ?? null ?: $it->supplier_name ?: $it->supplier_email);
            foreach ($offers->get($it->id, collect()) as $o) {
                $base = ['id' => (int) $o->id, 'sku' => $sku, 'cid' => (int) $it->cid, 'name' => (string) $it->name, 'key' => $key, 'supplier' => $supplier,
                    'email' => (string) $it->supplier_email, 'at' => Carbon::parse($o->at), 'email_message_id' => $o->email_message_id,
                    'request_id' => $it->related_request_id, 'request_code' => $it->internal_code];
                if ($o->outcome === 'quoted' && (float) $o->price > 0) {
                    $rub = IqotCurrencyConverter::toRub((float) $o->price, $o->currency);
                    // Последний ответ поставщика по позиции — его текущая цена (как в SupplierChoiceService::currentOffers).
                    $out[$sku]['offers'][$key] = $base + ['price' => (float) $o->price, 'currency' => $o->currency ?: 'RUB',
                        'rub' => $rub['known'] ? $rub['rub'] : null, 'quote' => $o->raw_quote, 'valid' => $o->valid_until_text,
                        'deviation' => PriceExportService::isDeviation((string) $o->raw_quote),
                        'comment' => PriceExportService::mailComment($o->valid_until_text, $o->raw_quote)];
                    unset($out[$sku]['declines'][$key]);
                } elseif ($o->outcome === 'refused' && ! isset($out[$sku]['offers'][$key])) {
                    $out[$sku]['declines'][$key] = $base + ['reason' => $o->refusal_reason];
                }
            }
        }

        return array_map(fn ($s) => [
            'asked' => array_keys($s['asked']),
            'offered' => array_keys($s['offers']),
            'declined' => array_keys($s['declines']),
            'requested_at' => $s['requested_at'],
            'offers' => collect(array_values($s['offers']))->sortBy(fn ($r) => $r['rub'] ?? PHP_FLOAT_MAX)->values(),
            'declines' => collect(array_values($s['declines'])),
        ], $out);
    }
}
