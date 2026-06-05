<?php

namespace App\Services\Iqot;

use App\Enums\IqotPositionStatus;
use App\Enums\QuotationStatus;
use App\Enums\RequestStatus;
use App\Models\CatalogItem;
use App\Models\IqotPosition;
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
     * Собрать/обновить auto-позиции пула из проигранных КП.
     *
     * @return array{created:int, updated:int, skipped_fresh:int}
     */
    public function refreshPoolFromLostQuotes(): array
    {
        $freshDays = $this->freshDays();

        $rows = DB::table('quotation_items as qi')
            ->join('quotations as q', 'q.id', '=', 'qi.quotation_id')
            ->join('requests as r', 'r.id', '=', 'q.request_id')
            ->where('q.status', QuotationStatus::Sent->value)
            ->where('r.status', RequestStatus::ClosedLost->value)
            ->whereNotNull('qi.catalog_item_id')
            ->groupBy('qi.catalog_item_id')
            ->selectRaw('qi.catalog_item_id, COUNT(DISTINCT q.id) AS cnt')
            ->get();

        $created = 0;
        $updated = 0;
        $skippedFresh = 0;

        foreach ($rows as $row) {
            $pos = IqotPosition::firstOrNew(['catalog_item_id' => (int) $row->catalog_item_id]);
            $cnt = (int) $row->cnt;
            $isNew = ! $pos->exists;

            // Свежий отчёт — только обновляем частоту, статус не трогаем.
            if (! $isNew && $pos->hasFreshReport($freshDays)) {
                if ((int) $pos->lost_quote_count !== $cnt) {
                    $pos->lost_quote_count = $cnt;
                    $pos->save();
                }
                $skippedFresh++;

                continue;
            }

            $pos->lost_quote_count = $cnt;
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
        if (! $pos->exists) {
            $pos->lost_quote_count = 0;
        }
        $pos->source = IqotPosition::SOURCE_MANUAL;
        $pos->requested_by_user_id = $userId;
        $pos->manual_requested_at = now();
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
        $unit = trim((string) ($ci->unit_name ?? '')) ?: 'шт.';

        $line = [
            'client_ref' => 'pos-' . $pos->id,
            'name' => $name,
            'quantity' => 1.0,
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
