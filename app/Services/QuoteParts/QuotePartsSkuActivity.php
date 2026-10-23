<?php

namespace App\Services\QuoteParts;

use App\Models\QuotePartsItemDecline;
use App\Models\QuotePartsOffer;
use App\Models\QuotePartsRfq;
use App\Services\Iqot\IqotCurrencyConverter;
use Illuminate\Support\Carbon;

/**
 * Движение по позиции в QuoteParts — для вкладок «К актуализации» и «В QuoteParts», где иначе видно
 * только «В работе»: действующие предложения поставщиков (сколько, почём дешевле всего), вопросы
 * поставщиков, ждущие нашего ответа, и отказы (по позиции из пула и в запросах за окно).
 */
class QuotePartsSkuActivity
{
    /**
     * @param  list<string>  $skus
     * @return array<string, array{offers:int, best:?array{price:float, currency:string}, questions:int, declined:int, last_at:?Carbon}>
     */
    public function for(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map('strval', $skus))));
        if ($skus === []) {
            return [];
        }
        $out = [];
        $row = function (string $sku) use (&$out) {
            return $out[$sku] ??= ['offers' => 0, 'best' => null, 'best_rub' => null, 'questions' => [], 'declined' => [], 'last_at' => null];
        };
        $touch = function (string $sku, $at) use (&$out) {
            $at = $at ? Carbon::parse($at) : null;
            if ($at && ($out[$sku]['last_at'] === null || $at->gt($out[$sku]['last_at']))) {
                $out[$sku]['last_at'] = $at;
            }
        };

        $offers = QuotePartsOffer::query()->whereIn('sku', $skus)
            ->where('superseded', false)->whereIn('status', ['active', 'expiring'])->whereNull('ignored_at')
            ->get(['id', 'sku', 'price', 'currency', 'created_at', 'remote_updated_at']);
        foreach ($offers as $o) {
            $r = $row((string) $o->sku);
            $r['offers']++;
            if ($o->price !== null) {
                $rub = IqotCurrencyConverter::toRub((float) $o->price, $o->currency);
                if ($rub['known'] && ($r['best_rub'] === null || $rub['rub'] < $r['best_rub'])) {
                    $r['best_rub'] = $rub['rub'];
                    $r['best'] = ['price' => (float) $o->price, 'currency' => (string) ($o->currency ?: 'RUB')];
                }
            }
            $out[$o->sku] = $r;
            $touch((string) $o->sku, $o->remote_updated_at ?? $o->created_at);
        }

        foreach (QuotePartsItemDecline::query()->inForce()->whereIn('sku', $skus)->get(['sku', 'supplier_key', 'declined_at']) as $d) {
            $r = $row((string) $d->sku);
            $r['declined'][(string) $d->supplier_key] = true;
            $out[$d->sku] = $r;
            $touch((string) $d->sku, $d->declined_at);
        }

        // Запросы за окно: отказы и вопросы поставщиков по строкам с этими артикулами.
        $wanted = array_flip($skus);
        $rfqs = QuotePartsRfq::query()->where('created_at', '>=', now()->subDays(PriceRefreshReadiness::WINDOW_DAYS))->get(['id', 'snapshot']);
        foreach ($rfqs as $rfq) {
            foreach ((array) ($rfq->snapshot['suppliers'] ?? []) as $s) {
                $key = (string) ($s['external_id'] ?? '');
                foreach ((array) ($s['lines'] ?? []) as $line) {
                    $code = (string) ($line['code'] ?? '');
                    $status = (string) ($line['status'] ?? '');
                    if (! isset($wanted[$code]) || ! in_array($status, ['declined', 'question'], true)) {
                        continue;
                    }
                    $r = $row($code);
                    $r[$status === 'declined' ? 'declined' : 'questions'][$key] = true;
                    $out[$code] = $r;
                    $touch($code, $s['last_reply_at'] ?? null);
                }
            }
        }

        return array_map(fn ($r) => [
            'offers' => $r['offers'],
            'best' => $r['best'],
            'questions' => count($r['questions']),
            'declined' => count($r['declined']),
            'last_at' => $r['last_at'],
        ], $out);
    }
}
