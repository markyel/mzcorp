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
 *  - auto: позиции из проигранных КП (quotation.status=sent + request closed_lost),
 *    дедуп по catalog_item_id, lost_quote_count = число таких КП (приоритет);
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

        // Базовый фильтр: closed_lost заявки, что были «КП отправлено» (любым
        // путём — фиксируется переходом статуса в quoted), их активные позиции,
        // сматченные с каталогом.
        $base = DB::table('request_items as ri')
            ->join('requests as r', 'r.id', '=', 'ri.request_id')
            ->where('r.status', RequestStatus::ClosedLost->value)
            ->where('ri.is_active', true)
            ->whereNotNull('ri.catalog_item_id')
            ->whereExists(function ($s) {
                $s->from('request_state_changes as sc')
                    ->whereColumn('sc.request_id', 'r.id')
                    ->where('sc.to_status', RequestStatus::Quoted->value);
            });

        $rows = (clone $base)
            ->groupBy('ri.catalog_item_id')
            ->selectRaw('ri.catalog_item_id, COUNT(DISTINCT r.id) AS cnt')
            ->get();

        // Последняя (по дате закрытия) исходная позиция на каждый catalog_item —
        // для qty/unit и нашей цены.
        $latest = (clone $base)
            ->orderBy('ri.catalog_item_id')
            ->orderByRaw('r.closed_at DESC NULLS LAST')
            ->orderByDesc('r.id')
            ->selectRaw('DISTINCT ON (ri.catalog_item_id) ri.catalog_item_id, ri.id AS request_item_id, r.id AS request_id')
            ->get()
            ->keyBy('catalog_item_id');

        $items = $latest->isEmpty()
            ? collect()
            : RequestItem::whereIn('id', $latest->pluck('request_item_id'))->get()->keyBy('id');

        // Наша цена из структурного КП (Quotation sent) по (request_id, catalog_item_id),
        // если он был; для детектор-пути структурного КП нет — фолбэк ниже на $oq.
        $kp = collect();
        if ($latest->isNotEmpty()) {
            $kp = DB::table('quotation_items as qi')
                ->join('quotations as q', 'q.id', '=', 'qi.quotation_id')
                ->where('q.status', QuotationStatus::Sent->value)
                ->whereIn('q.request_id', $latest->pluck('request_id')->unique()->values()->all())
                ->whereNotNull('qi.catalog_item_id')
                ->orderBy('q.request_id')
                ->orderBy('qi.catalog_item_id')
                ->orderByRaw('q.sent_at DESC NULLS LAST')
                ->orderByDesc('q.id')
                ->selectRaw('DISTINCT ON (q.request_id, qi.catalog_item_id) q.request_id, qi.catalog_item_id, qi.final_unit_price, q.internal_code')
                ->get()
                ->keyBy(fn ($r) => $r->request_id.':'.$r->catalog_item_id);
        }

        // Цена из ДЕТЕКТОР-КП (распарсенное исходящее КП-вложение): почти все КП
        // в MyLift уходят так, структурного Quotation нет. Берём самую свежую
        // сматченную строку с ценой > 0 по catalog_item среди ВСЕХ проигранных
        // заявок этой позиции (не только последней — у последней строки может
        // не быть). Иначе our_unit_price=null → priceComparison падал на
        // каталожную цену (часто 0,00 при is_price_actual=false). По одному
        // значению на catalog_item.
        $allReqIds = (clone $base)->distinct()->pluck('r.id')->all();
        $oq = collect();
        if ($allReqIds !== []) {
            $oq = DB::table('outbound_quote_items as oqi')
                ->join('outbound_quotes as oq2', 'oq2.id', '=', 'oqi.outbound_quote_id')
                ->whereIn('oq2.request_id', $allReqIds)
                ->whereNotNull('oqi.matched_catalog_item_id')
                ->where('oqi.unit_price', '>', 0)
                ->orderBy('oqi.matched_catalog_item_id')
                ->orderByRaw('oq2.document_date DESC NULLS LAST')
                ->orderByDesc('oq2.id')
                ->selectRaw('DISTINCT ON (oqi.matched_catalog_item_id) oqi.matched_catalog_item_id AS catalog_item_id, oqi.unit_price, oq2.document_number')
                ->get()
                ->keyBy('catalog_item_id');
        }

        $created = 0;
        $updated = 0;
        $skippedFresh = 0;

        foreach ($rows as $row) {
            $catId = (int) $row->catalog_item_id;
            $cnt = (int) $row->cnt;
            $pos = IqotPosition::firstOrNew(['catalog_item_id' => $catId]);
            $isNew = ! $pos->exists;

            $lt = $latest->get($catId);
            $ri = $lt ? $items->get($lt->request_item_id) : null;
            $kpRow = $lt ? $kp->get($lt->request_id.':'.$catId) : null;

            // Метаданные обновляем ВСЕГДА — в т.ч. у исключённых/свежих.
            $pos->lost_quote_count = $cnt;
            if ($ri) {
                $eq = $ri->effectiveQty();
                $pos->qty = $eq > 0 ? $eq : ((float) ($ri->parsed_qty ?: 1));
                $pos->unit = $ri->effectiveUnit() ?: (trim((string) ($ri->parsed_unit ?? '')) ?: null);
            }
            // Приоритет: структурный КП → детектор-КП → пусто (priceComparison
            // фолбэкнет на каталог при наличии цены > 0). Цену детектор-КП ищем
            // по catalog_item (across all lost quotes), не привязываясь к latest.
            $oqRow = $oq->get($catId);
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

        return ['created' => $created, 'updated' => $updated, 'skipped_fresh' => $skippedFresh];
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
