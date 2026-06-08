<?php

namespace App\Models;

use App\Enums\IqotPositionStatus;
use App\Services\Iqot\IqotCurrencyConverter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Позиция каталога в пуле IQOT-анализа + кэш результата по этой позиции.
 * Одна строка на catalog_item. См. миграцию create_iqot_positions_table.
 */
class IqotPosition extends Model
{
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'catalog_item_id',
        'iqot_submission_id',
        'requested_by_user_id',
        'status',
        'source',
        'lost_quote_count',
        'manual_requested_at',
        'qty',
        'unit',
        'our_unit_price',
        'our_quotation_code',
        'client_ref',
        'payload_name',
        'payload_oem',
        'payload_brand',
        'report',
        'report_min_price',
        'report_offers_count',
        'iqot_item_status',
        'analyzed_at',
        'last_enqueued_at',
        'excluded_at',
        'excluded_by_user_id',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'lost_quote_count' => 'integer',
        'qty' => 'decimal:3',
        'our_unit_price' => 'decimal:2',
        'report_offers_count' => 'integer',
        'report_min_price' => 'decimal:2',
        'report' => 'array',
        'manual_requested_at' => 'datetime',
        'analyzed_at' => 'datetime',
        'last_enqueued_at' => 'datetime',
        'excluded_at' => 'datetime',
    ];

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(IqotSubmission::class, 'iqot_submission_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function excludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by_user_id');
    }

    public function isExcluded(): bool
    {
        return $this->excluded_at !== null;
    }

    /**
     * Список предложений поставщиков из отчёта IQOT (report.all_offers).
     *
     * @return list<array<string, mixed>>
     */
    public function offers(): array
    {
        $offers = is_array($this->report ?? null) ? ($this->report['all_offers'] ?? []) : [];

        return is_array($offers) ? array_values(array_filter($offers, 'is_array')) : [];
    }

    /**
     * Наглядное сравнение «наше КП vs офферы IQOT» (как в LazyLift).
     * Сортировка по цене БЕЗ НДС (офферы с НДС приводим делением на 1+ставка);
     * в выводе сохраняем исходную цену оффера и пометку с/без НДС. Наша цена
     * (our_unit_price) уже NET. Возвращает строки + ранг нашего КП + дельту
     * против лучшего IQOT.
     *
     * @return array{
     *   rows: list<array<string,mixed>>, total:int, our_rank:?int,
     *   best_iqot_net:?float, delta:?float, delta_pct:?float, our_quotation_code:?string
     * }
     */
    public function priceComparison(): array
    {
        $rate = (float) app_setting('tax.vat_percent', config('services.tax.vat_percent', 22)) / 100;

        // Наша база сравнения: цена из ПОСЛЕДНЕГО проигранного КП (our_unit_price),
        // а если КП по позиции не было (ручной мониторинг) — фолбэк на цену
        // КАТАЛОГА. ВАЖНО: и КП-цена, и catalog.price указаны С НДС (gross) —
        // для сравнения приводим к без-НДС (делим на 1+ставка), как и офферы.
        $cat = $this->catalogItem;
        $ourGross = null;
        $ourLabel = null;
        $ourNotes = null;
        $ourLead = null;
        if ($this->our_unit_price !== null) {
            $ourGross = (float) $this->our_unit_price;
            $ourLabel = 'Наше КП' . ($this->our_quotation_code ? ' ' . $this->our_quotation_code : '');
            $ourNotes = 'собственное КП';
        } elseif ($cat && $cat->price !== null) {
            $ourGross = (float) $cat->price;
            $ourLabel = 'Наша цена (каталог)';
            $ourLead = $cat->lead_time_days;
            $notes = [];
            if ($cat->price_min !== null) {
                $notes[] = 'мин. ' . number_format((float) $cat->price_min, 2, ',', ' ') . ' ₽';
            }
            if (! $cat->is_price_actual) {
                $notes[] = 'цена не актуальна';
            }
            $ourNotes = $notes === [] ? null : implode(' · ', $notes);
        }
        $ourNet = $ourGross === null ? null : ($rate > 0 ? $ourGross / (1 + $rate) : $ourGross);

        $rows = [];
        foreach ($this->offers() as $o) {
            $raw = $o['price_per_unit'] ?? $o['total_price'] ?? $o['price'] ?? null;
            if (! is_numeric($raw)) {
                continue;
            }
            $raw = (float) $raw;
            $inclVat = array_key_exists('price_includes_vat', $o)
                ? (bool) $o['price_includes_vat']
                : (isset($o['vat_label']) && mb_stripos((string) $o['vat_label'], 'без') === false);
            $net = $inclVat && $rate > 0 ? $raw / (1 + $rate) : $raw;

            // Валюта оффера → рублёвый эквивалент (для ранга/дельты). Офферы
            // IQOT бывают в USD/EUR/CNY; без конвертации «80 USD» сравнивалось
            // бы как «80 ₽». netRub null, если курс валюты не задан в Настройках.
            $currency = IqotCurrencyConverter::normalize($o['currency'] ?? null);
            $rawConv = IqotCurrencyConverter::toRub($raw, $currency);
            $netConv = IqotCurrencyConverter::toRub($net, $currency);

            $rows[] = [
                'is_ours' => false,
                'supplier' => $o['supplier']['name'] ?? ('#' . ($o['supplier_id'] ?? '?')),
                'email' => $o['supplier']['email'] ?? null,
                'phone' => $o['supplier']['phone'] ?? null,
                'raw' => $raw,
                'net' => $net,
                'currency' => $currency,
                'currency_symbol' => IqotCurrencyConverter::symbol($currency),
                'converted' => $rawConv['converted'],
                'rate' => $rawConv['rate'],
                'rate_known' => $rawConv['known'],
                'raw_rub' => $rawConv['rub'],
                'net_rub' => $netConv['rub'],
                'includes_vat' => $inclVat,
                'vat_label' => $o['vat_label'] ?? ($inclVat ? 'с НДС' : 'без НДС'),
                'delivery_days' => $o['delivery_days'] ?? null,
                'total' => isset($o['total_price']) && is_numeric($o['total_price']) ? (float) $o['total_price'] : null,
                'received_at' => $o['received_at'] ?? null,
                'notes' => $o['notes'] ?? null,
            ];
        }

        if ($ourNet !== null) {
            $rows[] = [
                'is_ours' => true,
                'supplier' => $ourLabel,
                'email' => null,
                'phone' => null,
                'raw' => $ourGross,
                'net' => $ourNet,
                'currency' => 'RUB',
                'currency_symbol' => '₽',
                'converted' => false,
                'rate' => 1.0,
                'rate_known' => true,
                'raw_rub' => $ourGross,
                'net_rub' => $ourNet,
                'includes_vat' => true,
                'vat_label' => 'с НДС',
                'delivery_days' => $ourLead,
                'total' => null,
                'received_at' => null,
                'notes' => $ourNotes,
            ];
        }

        // Ключ сортировки — рублёвый эквивалент net (net_rub). Строки с
        // неизвестным курсом (net_rub === null) уходят в конец и не участвуют
        // в расчёте «лучший IQOT» / ранга.
        usort($rows, function ($a, $b) {
            $an = $a['net_rub'];
            $bn = $b['net_rub'];
            if ($an === null && $bn === null) {
                return $a['net'] <=> $b['net'];
            }
            if ($an === null) {
                return 1;
            }
            if ($bn === null) {
                return -1;
            }

            return $an <=> $bn;
        });

        $ourRank = null;
        foreach ($rows as $i => $r) {
            if ($r['is_ours']) {
                $ourRank = $i + 1;
                break;
            }
        }
        $bestIqotNet = null;
        foreach ($rows as $r) {
            if (! $r['is_ours'] && $r['net_rub'] !== null) {
                $bestIqotNet = $bestIqotNet === null ? $r['net_rub'] : min($bestIqotNet, $r['net_rub']);
            }
        }
        $delta = null;
        $deltaPct = null;
        if ($ourNet !== null && $bestIqotNet !== null && $bestIqotNet > 0) {
            $delta = $ourNet - $bestIqotNet;
            $deltaPct = $delta / $bestIqotNet * 100;
        }

        return [
            'rows' => $rows,
            'total' => count($rows),
            'our_rank' => $ourRank,
            'best_iqot_net' => $bestIqotNet,
            'delta' => $delta,
            'delta_pct' => $deltaPct,
            'our_label' => $ourLabel,
        ];
    }

    /**
     * Мин. цена за единицу из отчёта В РУБЛЁВОМ ЭКВИВАЛЕНТЕ (для бэкфилла
     * report_min_price). НЕ доверяем `best_offer_by_price` — IQOT выбирает его
     * по «голому» числу без учёта валюты (80 USD «дешевле» 6000 RUB). Считаем
     * min по all_offers с конвертацией каждого оффера по его валюте.
     */
    public function minPriceFromReport(): ?float
    {
        return IqotCurrencyConverter::minRawRub($this->offers());
    }

    public function statusEnum(): ?IqotPositionStatus
    {
        return IqotPositionStatus::tryFrom((string) $this->status);
    }

    public function hasReport(): bool
    {
        return $this->analyzed_at !== null && is_array($this->report) && ! empty($this->report);
    }

    /**
     * Свежий отчёт = есть отчёт и analyzed_at в окне актуальности
     * (iqot.report_fresh_days). По умолчанию 90 дней.
     */
    public function hasFreshReport(?int $freshDays = null): bool
    {
        if (! $this->hasReport()) {
            return false;
        }
        $freshDays ??= (int) app_setting('iqot.report_fresh_days', config('services.iqot.report_fresh_days', 90));

        return $this->analyzed_at->gte(now()->subDays(max(1, $freshDays)));
    }

    /**
     * Позиции со свежим отчётом (для подсветки/дедупа). Окно — в днях.
     */
    public function scopeWithFreshReport(Builder $q, int $freshDays): Builder
    {
        return $q->whereNotNull('analyzed_at')
            ->where('analyzed_at', '>=', now()->subDays(max(1, $freshDays)));
    }
}
