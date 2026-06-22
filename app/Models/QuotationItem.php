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
 * @property ?int $catalog_lead_time_days
 * @property bool $catalog_in_stock
 * @property ?int $catalog_stock_available
 * @property string $snapshot_name
 * @property ?string $snapshot_sku
 * @property ?string $snapshot_brand
 * @property ?string $snapshot_brand_article
 * @property ?string $snapshot_photo_url
 * @property float $qty
 * @property string $unit
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
        'catalog_lead_time_days',
        'catalog_in_stock',
        'catalog_stock_available',
        'snapshot_name',
        'snapshot_sku',
        'snapshot_brand',
        'snapshot_brand_article',
        'snapshot_photo_url',
        'qty',
        'unit',
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
            'catalog_lead_time_days' => 'integer',
            'catalog_in_stock' => 'boolean',
            'catalog_stock_available' => 'integer',
            'qty' => 'decimal:3',
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
     * Строки срока поставки для КП. Обычно одна; при ЧАСТИЧНОМ наличии
     * (0 < свободный остаток < кол-во) — ДВЕ строки: «Со склада» (из наличия)
     * + «Под заказ ≈ N нед» (остаток). Номер позиции при этом ОДИН (название и
     * цена общие через rowspan в шаблоне), дробится только кол-во/срок/сумма.
     * Суммы делятся пропорционально (вторая строка добирает остаток после
     * округления), так что итог по позиции = сумма строк. Ручной delivery_text
     * — абсолютный override (одна строка как есть, без разбивки).
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

        if ($stock <= 0) {
            return $single($orderTerm); // на складе нет — весь объём под заказ
        }
        if ($stock >= $qty) {
            return $single('Со склада'); // наличия хватает на всё кол-во
        }

        // Частично: наличие + остаток под заказ (две строки под одним номером).
        $unitPrice = (float) $this->final_unit_price;
        $sub1Total = round($stock * $unitPrice, 2);
        $sub1Vat = $lineTotal > 0.0 ? round($vat * ($sub1Total / $lineTotal), 2) : 0.0;

        return [
            [
                'qty' => (float) $stock,
                'term' => 'Со склада',
                'line_total' => $sub1Total,
                'vat_amount' => $sub1Vat,
            ],
            [
                'qty' => $qty - $stock,
                'term' => $orderTerm,
                'line_total' => round($lineTotal - $sub1Total, 2),
                'vat_amount' => round($vat - $sub1Vat, 2),
            ],
        ];
    }
}
