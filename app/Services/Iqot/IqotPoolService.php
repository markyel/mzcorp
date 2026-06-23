<?php

namespace App\Services\Iqot;

use App\Enums\IqotPositionStatus;
use App\Enums\QuotationStatus;
use App\Enums\RequestStatus;
use App\Models\CatalogItem;
use App\Models\IqotPosition;
use App\Models\RequestItem;
use Illuminate\Support\Facades\DB;

/**
 * Наполнение пула IQOT-анализа позициями каталога.
 *
 * Источники:
 *  - auto: позиции, РЕАЛЬНО проквотированные в проигранных заявках (есть строка в
 *    структурном Quotation(sent) ИЛИ в детектор-КП с ценой > 0); дедуп по
 *    catalog_item_id, lost_quote_count = число таких заявок (приоритет). Позиции,
 *    что были в заявке, но в КП не попали, НЕ берутся (и чистятся из пула).
 *  - manual: ручное добавление из карточки каталога (РОП/директор), всегда приоритет.
 *
 * Позиция со свежим отчётом (окно iqot.report_fresh_days) повторно не ставится.
 */
class IqotPoolService
{
    private function freshDays(): int
    {
        return (int) app_setting('iqot.report_fresh_days', config('services.iqot.report_fresh_days', 90));
    }

    /**
     * Собрать/обновить auto-позиции пула.
     *
     * Источник — catalog-matched позиции заявок, которые были в статусе «КП
     * отправлено» (ЛЮБЫМ путём: структурный Quotation ИЛИ детектор вложения) и
     * закрыты в потерю. Дедуп по catalog_item_id; lost_quote_count = число таких
     * заявок. qty/unit — из позиции заявки (effectiveQty/effectiveUnit, последняя
     * по дате закрытия). Наша цена: структурный КП → детектор-КП (распарсенное
     * вложение) → пусто (сравнение фолбэкнет на каталог, если цена > 0).
     *
     * @return array{created:int, updated:int, skipped_fresh:int}
     */
    public function refreshPoolFromLostQuotes(): array
    {
        $freshDays = $this->freshDays();

        // === Реально ПРОКВОТИРОВАННЫЕ позиции в проигранных заявках ===
        // Позиция входит в мониторинг ТОЛЬКО если попала в КП клиенту: структурный
        // Quotation(sent) ИЛИ распарсенное исходящее КП-вложение (детектор, цена
        // > 0). Позиции, что были в заявке, но в КП не попали (мы их не
        // квотировали — напр. нет цены), НЕ берём: единица IQOT-анализа — «лот, на
        // который мы ДАЛИ цену и проиграли». Раньше брали все request_items
        // closed_lost+quoted заявки → в пул лезли неквотированные позиции (кейс
        // M26928: башмак был в заявке, но в КП не попал).
        $struct = DB::table('quotation_items as qi')
            ->join('quotations as q', 'q.id', '=', 'qi.quotation_id')
            ->join('requests as r', 'r.id', '=', 'q.request_id')
            ->where('r.status', RequestStatus::ClosedLost->value)
            ->where('q.status', QuotationStatus::Sent->value)
            ->whereNotNull('qi.catalog_item_id')
            ->selectRaw('qi.catalog_item_id, q.request_id, r.closed_at')
            ->get();

        $det = DB::table('outbound_quote_items as oqi')
            ->join('outbound_quotes as oq', 'oq.id', '=', 'oqi.outbound_quote_id')
            ->join('requests as r', 'r.id', '=', 'oq.request_id')
            ->where('r.status', RequestStatus::ClosedLost->value)
            ->whereNotNull('oqi.matched_catalog_item_id')
            ->where('oqi.unit_price', '>', 0)
            ->selectRaw('oqi.matched_catalog_item_id AS catalog_item_id, oq.request_id, r.closed_at')
            ->get();

        // cid => [request_id => closed_at] — для счётчика и выбора последней заявки.
        $quoted = [];
        foreach ($struct->concat($det) as $r) {
            $cid = (int) $r->catalog_item_id;
            $quoted[$cid][(int) $r->request_id] = (string) ($r->closed_at ?? '');
        }
        $quotedCatIds = array_keys($quoted);

        // Гард: пустой quoted-set (миграция/сбой) — пул не трогаем (иначе чистка
        // ниже снесла бы весь auto-пул).
        if ($quotedCatIds === []) {
            return ['created' => 0, 'updated' => 0, 'skipped_fresh' => 0, 'removed' => 0];
        }

        // Последняя проквотированная заявка на каждый cid (по closed_at).
        $latestReqByCid = [];
        foreach ($quoted as $cid => $reqs) {
            arsort($reqs); // closed_at-строки ISO сортируются хронологически
            $latestReqByCid[$cid] = (int) array_key_first($reqs);
        }
        $allQuotedReqIds = array_values(array_unique(array_merge(
            ...array_map('array_keys', array_values($quoted))
        )));

        // qty/unit — из активной позиции ПОСЛЕДНЕЙ проквотированной заявки.
        $reqItems = RequestItem::query()
            ->whereIn('catalog_item_id', $quotedCatIds)
            ->whereIn('request_id', array_values($latestReqByCid))
            ->where('is_active', true)
            ->get()
            ->groupBy(fn ($ri) => $ri->request_id.':'.$ri->catalog_item_id);

        // Структурная цена по (request_id, cid) для последних заявок.
        $kp = DB::table('quotation_items as qi')
            ->join('quotations as q', 'q.id', '=', 'qi.quotation_id')
            ->where('q.status', QuotationStatus::Sent->value)
            ->whereIn('q.request_id', array_values($latestReqByCid))
            ->whereNotNull('qi.catalog_item_id')
            ->orderBy('q.request_id')
            ->orderBy('qi.catalog_item_id')
            ->orderByRaw('q.sent_at DESC NULLS LAST')
            ->orderByDesc('q.id')
            ->selectRaw('DISTINCT ON (q.request_id, qi.catalog_item_id) q.request_id, qi.catalog_item_id, qi.final_unit_price, q.internal_code')
            ->get()
            ->keyBy(fn ($r) => $r->request_id.':'.$r->catalog_item_id);

        // Детектор-цена по cid: самая свежая сматченная строка с ценой > 0 среди
        // ВСЕХ проигранных квот этой позиции. По одному значению на catalog_item.
        $oq = DB::table('outbound_quote_items as oqi')
            ->join('outbound_quotes as oq2', 'oq2.id', '=', 'oqi.outbound_quote_id')
            ->whereIn('oq2.request_id', $allQuotedReqIds)
            ->whereNotNull('oqi.matched_catalog_item_id')
            ->where('oqi.unit_price', '>', 0)
            ->orderBy('oqi.matched_catalog_item_id')
            ->orderByRaw('oq2.document_date DESC NULLS LAST')
            ->orderByDesc('oq2.id')
            ->selectRaw('DISTINCT ON (oqi.matched_catalog_item_id) oqi.matched_catalog_item_id AS catalog_item_id, oqi.unit_price, oq2.document_number')
            ->get()
            ->keyBy('catalog_item_id');

        $created = 0;
        $updated = 0;
        $skippedFresh = 0;

        foreach ($quotedCatIds as $catId) {
            $cnt = count($quoted[$catId]); // distinct проигранных заявок, где КВОТИРОВАНА
            $pos = IqotPosition::firstOrNew(['catalog_item_id' => $catId]);
            $isNew = ! $pos->exists;

            $latestReqId = $latestReqByCid[$catId];
            $ri = optional($reqItems->get($latestReqId.':'.$catId))->first();
            $kpRow = $kp->get($latestReqId.':'.$catId);
            $oqRow = $oq->get($catId);

            // Метаданные обновляем ВСЕГДА — в т.ч. у исключённых/свежих.
            $pos->lost_quote_count = $cnt;
            if ($ri) {
                $eq = $ri->effectiveQty();
                $pos->qty = $eq > 0 ? $eq : ((float) ($ri->parsed_qty ?: 1));
                $pos->unit = $ri->effectiveUnit() ?: (trim((string) ($ri->parsed_unit ?? '')) ?: null);
            }
            // Приоритет нашей цены: структурный КП → детектор-КП → пусто
            // (priceComparison фолбэкнет на каталог при цене > 0).
            if ($kpRow && $kpRow->final_unit_price !== null) {
                $pos->our_unit_price = (float) $kpRow->final_unit_price;
                $pos->our_quotation_code = trim((string) $kpRow->internal_code) ?: null;
            } elseif ($oqRow && (float) $oqRow->unit_price > 0) {
                $pos->our_unit_price = (float) $oqRow->unit_price;
                $pos->our_quotation_code = trim((string) $oqRow->document_number) ?: null;
            } else {
                $pos->our_unit_price = null;
                $pos->our_quotation_code = null;
            }

            // Исключена вручную («не запрашивать никогда») — статус не трогаем.
            if (! $isNew && $pos->isExcluded()) {
                $pos->save();

                continue;
            }

            // Свежий отчёт — не пере-анализируем, статус не трогаем.
            if (! $isNew && $pos->hasFreshReport($freshDays)) {
                $pos->save();
                $skippedFresh++;

                continue;
            }

            if ($isNew) {
                $pos->source = IqotPosition::SOURCE_AUTO;
                $pos->status = IqotPositionStatus::Pending->value;
            } elseif (! ($pos->statusEnum()?->isOpen() ?? false)) {
                // Был completed (отчёт устарел) / failed → вернуть в очередь.
                $pos->status = IqotPositionStatus::Pending->value;
            }
            $pos->save();
            $isNew ? $created++ : $updated++;
        }

        // Чистка legacy: auto-позиции, которые по новому алгоритму НЕ были реально
        // квотированы (старый алгоритм брал все позиции заявки). Удаляем ТОЛЬКО
        // нетронутые: не отправлялись в IQOT и без отчёта (баланс не потрачен).
        // Позиции, по которым средства УЖЕ списаны (last_enqueued_at/analyzed_at) —
        // НЕ трогаем, даже если по новому алгоритму они не квотированы: данные
        // офферов конкурентов ценны и оплачены. Manual и вручную-исключённые
        // (excluded_at) тоже не трогаем. Гард непустого quotedCatIds — выше.
        $removed = IqotPosition::where('source', IqotPosition::SOURCE_AUTO)
            ->whereNull('excluded_at')
            ->whereNull('analyzed_at')
            ->whereNull('last_enqueued_at')
            ->whereNotIn('catalog_item_id', $quotedCatIds)
            ->delete();

        return ['created' => $created, 'updated' => $updated, 'skipped_fresh' => $skippedFresh, 'removed' => $removed];
    }

    /**
     * Ручное добавление позиции каталога в пул (из карточки каталога).
     * Принудительно ставит в очередь (manual = приоритет), даже если есть отчёт.
     */
    public function enqueueCatalogItem(int $catalogItemId, ?int $userId = null): IqotPosition
    {
        $pos = IqotPosition::firstOrNew(['catalog_item_id' => $catalogItemId]);
        // Есть свежий отчёт — повторно на расценку НЕ кидаем (бережём баланс IQOT).
        // Отчёт обновляется сам, пока submission собирает офферы; принудительный
        // повторный анализ возможен только после устаревания отчёта.
        if ($pos->exists && $pos->hasFreshReport()) {
            return $pos;
        }
        if (! $pos->exists) {
            $pos->lost_quote_count = 0;
        }
        $pos->source = IqotPosition::SOURCE_MANUAL;
        $pos->requested_by_user_id = $userId;
        $pos->manual_requested_at = now();
        // Явный ручной запрос снимает «исключено навсегда».
        $pos->excluded_at = null;
        $pos->excluded_by_user_id = null;
        // Не сбрасываем, если уже отправлена и ждём отчёт.
        if ($pos->status !== IqotPositionStatus::Analyzing->value) {
            $pos->status = IqotPositionStatus::Pending->value;
            $pos->error_code = null;
            $pos->error_message = null;
        }
        $pos->save();

        return $pos;
    }

    /**
     * Построить строку IQOT по позиции каталога. Шлём НАЗВАНИЕ каталога + OEM-код.
     * M-артикул (catalog_items.sku) в payload НЕ попадает.
     *
     * @return array{client_ref:string, name:string, quantity:float, unit:string, article?:string, brand?:string, client_category?:array}
     */
    public function buildLine(IqotPosition $pos): array
    {
        /** @var CatalogItem|null $ci */
        $ci = $pos->catalogItem;
        $name = trim((string) ($ci->name ?? ''));
        $oem = $ci?->oemForExternal() ?? '';
        $brand = $ci?->brandForExternal() ?? '';

        // Кол-во/единица — из последнего проигранного КП (см. refreshPool).
        // Каталожный unit_name НЕ используем: это поле «Узел», не единица измерения.
        $qty = (float) ($pos->qty ?? 0);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $unit = trim((string) ($pos->unit ?? '')) ?: 'шт.';

        $line = [
            'client_ref' => 'pos-'.$pos->id,
            'name' => $name,
            'quantity' => $qty,
            'unit' => $unit,
        ];
        if ($oem !== '') {
            $line['article'] = $oem;
        }
        if ($brand !== '') {
            $line['brand'] = $brand;
        }

        $root = trim((string) app_setting('iqot.root_category', config('services.iqot.root_category', '')));
        $leaf = trim((string) ($ci->part_type ?? ''));
        $path = array_values(array_filter([$root, $leaf], fn ($s) => $s !== ''));
        if ($path !== []) {
            $line['client_category'] = ['code' => end($path), 'path' => $path];
        }

        return $line;
    }
}
