<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $quotation_id
 * @property int $position
 * @property ?int $request_item_id
 * @property ?int $catalog_item_id
 * @property float $catalog_unit_price
 * @property ?float $catalog_price_min
 * @property ?float $catalog_purchase_price
 * @property ?int $catalog_lead_time_days
 * @property bool $catalog_in_stock
 * @property ?int $catalog_stock_available
 * @property ?array $catalog_stock_in_transit
 * @property string $snapshot_name
 * @property ?string $snapshot_sku
 * @property ?string $snapshot_brand
 * @property ?string $snapshot_brand_article
 * @property ?string $snapshot_photo_url
 * @property float $qty
 * @property string $unit
 * @property ?float $piece_length
 * @property ?string $piece_length_unit
 * @property bool $bill_by_length
 * @property ?float $discount_percent
 * @property float $final_unit_price
 * @property float $line_total
 * @property float $vat_amount
 * @property ?string $delivery_text
 * @property ?string $notes
 */
class QuotationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'position',
        'request_item_id',
        'catalog_item_id',
        'catalog_unit_price',
        'catalog_price_min',
        // Снапшот закупочной (себестоимости) — для режима cost_plus. Внутреннее.
        'catalog_purchase_price',
        'catalog_lead_time_days',
        'catalog_in_stock',
        'catalog_stock_available',
        // Снапшот свободных остатков в пути: [{qty:int, date:'Y-m-d'}].
        'catalog_stock_in_transit',
        'snapshot_name',
        'snapshot_sku',
        'snapshot_brand',
        'snapshot_brand_article',
        'snapshot_photo_url',
        'qty',
        'unit',
        'piece_length',
        'piece_length_unit',
        'bill_by_length',
        'discount_percent',
        'final_unit_price',
        'line_total',
        'vat_amount',
        'delivery_text',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'catalog_unit_price' => 'decimal:2',
            'catalog_price_min' => 'decimal:2',
            'catalog_purchase_price' => 'decimal:2',
            'catalog_lead_time_days' => 'integer',
            'catalog_in_stock' => 'boolean',
            'catalog_stock_available' => 'integer',
            'catalog_stock_in_transit' => 'array',
            'qty' => 'decimal:3',
            'piece_length' => 'decimal:3',
            'bill_by_length' => 'boolean',
            'discount_percent' => 'decimal:2',
            'final_unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'vat_amount' => 'decimal:2',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(RequestItem::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    /**
     * Двумерная позиция — есть снапшот второй размерности (напр. «6 шт × 55 м»).
     * qty при этом остаётся в штуках, piece_length — длина одного куска.
     */
    public function isMeasured(): bool
    {
        return $this->piece_length !== null && (float) $this->piece_length > 0;
    }

    /**
     * Количество для расчёта суммы:
     *   - bill_by_length (цена за метр) → qty × piece_length (6 × 55 = 330);
     *   - иначе (цена за штуку/бухту)   → просто qty (6).
     * Отображаемое qty («6 шт») и множитель метража хранятся раздельно —
     * это и отличает от прежнего бага, где метраж терялся или схлопывал qty.
     */
    public function billableQty(): float
    {
        $qty = (float) $this->qty;
        if ($this->bill_by_length && $this->isMeasured()) {
            return $qty * (float) $this->piece_length;
        }

        return $qty;
    }

    /**
     * РЕАЛЬНО предоставленная скидка (%) — считается от фактической цены
     * (`final_unit_price`), а не от назначенной `discount_percent`. Финальная
     * цена = MAX(цена×(1−назначенная скидка), минимальная продажная price_min)
     * (см. QuotationService::computeFinalUnitPrice), поэтому если минималка
     * перебила скидку — фактическая скидка МЕНЬШЕ выбранной, и в КП показываем
     * именно её. null, если по факту скидки нет (final ≥ каталожной).
     */
    public function realDiscountPercent(): ?float
    {
        $catalog = (float) $this->catalog_unit_price;
        $final = (float) $this->final_unit_price;
        if ($catalog <= 0.0 || $final >= $catalog) {
            return null;
        }

        return round((1 - $final / $catalog) * 100, 2);
    }

    /**
     * Строки срока поставки для КП. Обычно одна; при нехватке наличия объём
     * дробится по источникам (под одним номером позиции, rowspan в шаблоне):
     *   1) «Со склада»              — из свободного остатка (catalog_stock_available);
     *   2) «Поставка к DD.MM.YYYY»  — из свободных остатков в пути по датам прихода
     *                                 (раньше — выше), каждый приход = своя строка;
     *   3) «Под заказ ≈ N нед»      — остаток, которого нет ни в наличии, ни в пути.
     * Дробятся только кол-во/срок/сумма; название и цена общие. Суммы делятся
     * пропорционально ШТУКАМ, последняя строка добирает остаток после округления
     * (итог по позиции = сумма строк). Ручной delivery_text — абсолютный override
     * (одна строка как есть, без разбивки).
     *
     * @return array<int, array{qty: float, term: string, line_total: float, vat_amount: float}>
     */
    public function deliveryRows(): array
    {
        $qty = (float) $this->qty;
        $lineTotal = (float) $this->line_total;
        $vat = (float) $this->vat_amount;

        $single = fn (string $term): array => [[
            'qty' => $qty,
            'term' => $term,
            'line_total' => $lineTotal,
            'vat_amount' => $vat,
        ]];

        // Ручной срок — показываем как есть, без разбивки.
        $manual = trim((string) ($this->delivery_text ?? ''));
        if ($manual !== '') {
            return $single($manual);
        }

        // Срок под-заказа из базы (lead_time_days). Если в каталоге его нет
        // (0/пусто, ~45% под-заказ позиций) — честное «срок уточняется» вместо
        // выдуманного числа; явный срок показываем как «≈ N нед».
        $weeks = $this->catalog_lead_time_days ? (int) ceil($this->catalog_lead_time_days / 7) : null;
        $orderTerm = $weeks !== null ? "Под заказ ≈ {$weeks} нед" : 'Под заказ (срок уточняется)';

        $stock = $this->catalog_stock_available;
        if ($stock === null) {
            // Legacy без снапшота количества — по флагу, БЕЗ противоречивых недель.
            return $single($this->catalog_in_stock ? 'Со склада' : $orderTerm);
        }
        $stock = max(0, (int) $stock);

        // Аллокация кол-ва по источникам: наличие → приходы в пути (по датам) →
        // остаток под заказ. Каждый источник даёт под-строку (qty + term).
        $alloc = []; // array<int, array{qty: float, term: string}>
        $remaining = $qty;

        if ($stock > 0 && $remaining > 0) {
            $take = min((float) $stock, $remaining);
            $alloc[] = ['qty' => $take, 'term' => 'Со склада'];
            $remaining -= $take;
        }

        foreach ($this->transitLots() as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $take = min((float) $lot['qty'], $remaining);
            $alloc[] = ['qty' => $take, 'term' => 'Поставка к ' . $lot['date']];
            $remaining -= $take;
        }

        // Остаток (или вся позиция, если нет ни наличия, ни прихода) — под заказ.
        if ($remaining > 0 || $alloc === []) {
            $alloc[] = ['qty' => $remaining > 0 ? $remaining : $qty, 'term' => $orderTerm];
        }

        // Одна строка — деньги целиком (быстрый путь, без округлений).
        if (count($alloc) === 1) {
            return $single($alloc[0]['term']);
        }

        // Пропорциональное деление денег по ШТУКАМ (корректно и для мерных
        // позиций: метраж уже зашит в line_total, per-piece доля = line_total × qty_i/qty).
        // Последняя строка добирает остаток, чтобы сумма совпала с line_total.
        $rows = [];
        $accTotal = 0.0;
        $accVat = 0.0;
        $last = count($alloc) - 1;
        foreach ($alloc as $i => $a) {
            if ($i === $last) {
                $rowTotal = round($lineTotal - $accTotal, 2);
                $rowVat = round($vat - $accVat, 2);
            } else {
                $rowTotal = $qty > 0.0 ? round($lineTotal * ($a['qty'] / $qty), 2) : 0.0;
                $rowVat = $lineTotal > 0.0 ? round($vat * ($rowTotal / $lineTotal), 2) : 0.0;
                $accTotal += $rowTotal;
                $accVat += $rowVat;
            }
            $rows[] = [
                'qty' => $a['qty'],
                'term' => $a['term'],
                'line_total' => $rowTotal,
                'vat_amount' => $rowVat,
            ];
        }

        return $rows;
    }

    /**
     * Свободные остатки в пути (снапшот catalog_stock_in_transit) — нормализованный
     * список приходов, отсортированный по дате (раньше — выше). Пустой массив,
     * если снапшота нет (legacy КП) или он пуст.
     *
     * @return array<int, array{qty: int, date: string}>  date в формате DD.MM.YYYY
     */
    private function transitLots(): array
    {
        $raw = $this->catalog_stock_in_transit;
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $lots = [];
        foreach ($raw as $lot) {
            if (! is_array($lot)) {
                continue;
            }
            $qty = (int) ($lot['qty'] ?? 0);
            $date = trim((string) ($lot['date'] ?? ''));
            if ($qty <= 0 || $date === '') {
                continue;
            }
            $ts = strtotime($date);
            $lots[] = [
                'qty' => $qty,
                'date' => $ts ? date('d.m.Y', $ts) : $date,
                '_sort' => $ts ?: PHP_INT_MAX,
            ];
        }
        usort($lots, fn ($a, $b) => $a['_sort'] <=> $b['_sort']);

        return array_map(fn ($l) => ['qty' => $l['qty'], 'date' => $l['date']], $lots);
    }
}
