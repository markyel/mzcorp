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
        'cmp_our_rank',
        'cmp_deviation_pct',
        'cmp_total',
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
        'cmp_our_rank' => 'integer',
        'cmp_deviation_pct' => 'decimal:2',
        'cmp_total' => 'integer',
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
        // Цена ≤ 0 (в т.ч. каталожный 0,00 при отсутствии реальной цены —
        // is_price_actual=false) = «цена неизвестна», НЕ «бесплатно». Иначе наша
        // строка с 0 ₽ занимала бы ложное 1-е место и давала дельту −100%
        // (кейс M26928). Тот же принцип, что firstPositivePrice для офферов:
        // нет валидной цены → нашу строку в сравнение не добавляем (our_rank=null).
        if ($this->our_unit_price !== null && (float) $this->our_unit_price > 0) {
            $ourGross = (float) $this->our_unit_price;
            $ourLabel = 'Наше КП'.($this->our_quotation_code ? ' '.$this->our_quotation_code : '');
            $ourNotes = 'собственное КП';
        } elseif ($cat && $cat->price !== null && (float) $cat->price > 0) {
            $ourGross = (float) $cat->price;
            $ourLabel = 'Наша цена (каталог)';
            $ourLead = $cat->lead_time_days;
            $notes = [];
            if ($cat->price_min !== null) {
                $notes[] = 'мин. '.number_format((float) $cat->price_min, 2, ',', ' ').' ₽';
            }
            if (! $cat->is_price_actual) {
                $notes[] = 'цена не актуальна';
            }
            $ourNotes = $notes === [] ? null : implode(' · ', $notes);
        }
        $ourNet = $ourGross === null ? null : ($rate > 0 ? $ourGross / (1 + $rate) : $ourGross);

        $rows = [];
        foreach ($this->offers() as $o) {
            // Цена ≤ 0 — невалидный/пустой ответ поставщика (ошибка IQOT):
            // исключаем из сравнения, ранга и дельты (иначе «0 ₽» = ложное
            // 1-е место и сдвиг нашего ранга). Кейс M06476 / Revator 0 ₽.
            $raw = IqotCurrencyConverter::firstPositivePrice($o);
            if ($raw === null) {
                continue;
            }
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
                'supplier' => $o['supplier']['name'] ?? ('#'.($o['supplier_id'] ?? '?')),
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

    /** Порог места для флага внимания (наша цена на этом месте ИЛИ ниже). */
    public static function attentionMinRank(): int
    {
        return (int) app_setting('iqot.attention_min_rank', config('services.iqot.attention.min_rank', 3));
    }

    /** Порог отклонения от лучшей цены IQOT (без НДС, %) для флага внимания. */
    public static function attentionMinDeviationPct(): float
    {
        return (float) app_setting('iqot.attention_min_deviation_pct', config('services.iqot.attention.min_deviation_pct', 10));
    }

    /** Топ-N% самых дорогих на рынке для критического алерта. */
    public static function criticalTopPct(): float
    {
        return (float) app_setting('iqot.critical_top_pct', config('services.iqot.critical.top_pct', 20));
    }

    /** Минимум поставщиков (офферов IQOT) для критического алерта. */
    public static function criticalMinSuppliers(): int
    {
        return (int) app_setting('iqot.critical_min_suppliers', config('services.iqot.critical.min_suppliers', 4));
    }

    /**
     * «Кричащий» алерт: наша цена в топ-N% самых дорогих ПРИ выборке ≥
     * min_suppliers поставщиков. Считается по кешу cmp_*. Доля более дешёвых
     * участников = (rank-1)/total; «топ 20%» = эта доля ≥ 0.8.
     */
    public function isCriticalPricing(): bool
    {
        $rank = $this->cmp_our_rank;
        $total = $this->cmp_total;
        if ($rank === null || $total === null || $total < 1) {
            return false;
        }
        $suppliers = (int) $total - 1; // строки сравнения минус наша
        if ($suppliers < self::criticalMinSuppliers()) {
            return false;
        }
        $topFraction = 1 - self::criticalTopPct() / 100;

        return ((int) $rank - 1) / (int) $total >= $topFraction;
    }

    /** Выполнено ли «мягкое» условие внимания (место ≥ min_rank И dev > порога). */
    private function matchesAttention(): bool
    {
        return $this->cmp_our_rank !== null
            && $this->cmp_deviation_pct !== null
            && $this->cmp_our_rank >= self::attentionMinRank()
            && (float) $this->cmp_deviation_pct > self::attentionMinDeviationPct();
    }

    /**
     * Уровень алерта по ценообразованию + данные для бейджа/строки. Использует
     * КЕШ сравнения (cmp_*), теми же порогами что и SQL-скоупы — бейдж, фон
     * строки и фильтр согласованы.
     *  - 'critical'  — наша цена в топ-N% самых дорогих при ≥ min_suppliers (фон);
     *  - 'attention' — место ≥ min_rank И отклонение > порога (бейдж);
     *  - null        — внимание не требуется / сравнивать не с чем.
     *
     * @return array{level:'critical'|'attention', rank:int, total:?int, deviation_pct:?float, suppliers:?int}|null
     */
    public function pricingAlert(): ?array
    {
        if ($this->cmp_our_rank === null) {
            return null;
        }

        $level = null;
        if ($this->isCriticalPricing()) {
            $level = 'critical';
        } elseif ($this->matchesAttention()) {
            $level = 'attention';
        }
        if ($level === null) {
            return null;
        }

        $total = $this->cmp_total !== null ? (int) $this->cmp_total : null;

        return [
            'level' => $level,
            'rank' => (int) $this->cmp_our_rank,
            'total' => $total,
            'deviation_pct' => $this->cmp_deviation_pct !== null ? (float) $this->cmp_deviation_pct : null,
            'suppliers' => $total !== null ? $total - 1 : null,
        ];
    }

    /**
     * Пересчитать кеш сравнения (cmp_our_rank/cmp_deviation_pct/cmp_total) по
     * текущему отчёту и курсам. Вызывается при раскладке отчёта и при смене
     * курсов. НЕ сохраняет сам по себе при $persist=false (для batch-forceFill).
     *
     * @return array{cmp_our_rank:?int, cmp_deviation_pct:?float, cmp_total:?int}
     */
    public function recomputeComparisonCache(bool $persist = true): array
    {
        $cmp = $this->priceComparison();
        $values = [
            'cmp_our_rank' => $cmp['our_rank'],
            'cmp_deviation_pct' => $cmp['delta_pct'],
            'cmp_total' => $cmp['total'] ?: null,
        ];
        if ($persist) {
            $this->forceFill($values)->save();
        }

        return $values;
    }

    /**
     * SQL-скоуп: критический («кричащий») алерт — топ-N% самых дорогих при
     * ≥ min_suppliers поставщиков. (cmp_total-1) = число офферов; доля более
     * дешёвых = (cmp_our_rank-1)/cmp_total ≥ (1 - top_pct/100).
     */
    public function scopeCriticalPricing(Builder $q): Builder
    {
        $topFraction = 1 - self::criticalTopPct() / 100;

        return $q->whereNotNull('cmp_our_rank')
            ->whereNotNull('cmp_total')
            ->where('cmp_total', '>', 0)
            ->whereRaw('(cmp_total - 1) >= ?', [self::criticalMinSuppliers()])
            ->whereRaw('(cmp_our_rank - 1)::float / cmp_total >= ?', [$topFraction]);
    }

    /**
     * SQL-скоуп: позиции, требующие внимания к цене — «мягкий» (место/отклонение)
     * ИЛИ критический тир. Пагинируется на уровне БД, пороги текущие.
     */
    public function scopeNeedingPricingAttention(Builder $q): Builder
    {
        $topFraction = 1 - self::criticalTopPct() / 100;

        return $q->whereNotNull('cmp_our_rank')->where(function (Builder $w) use ($topFraction) {
            // мягкий: место ≥ порога И отклонение > порога
            $w->where(function (Builder $a) {
                $a->whereNotNull('cmp_deviation_pct')
                    ->where('cmp_our_rank', '>=', self::attentionMinRank())
                    ->where('cmp_deviation_pct', '>', self::attentionMinDeviationPct());
            })
            // критический: топ-N% при ≥ min_suppliers
                ->orWhere(function (Builder $c) use ($topFraction) {
                    $c->whereNotNull('cmp_total')
                        ->where('cmp_total', '>', 0)
                        ->whereRaw('(cmp_total - 1) >= ?', [self::criticalMinSuppliers()])
                        ->whereRaw('(cmp_our_rank - 1)::float / cmp_total >= ?', [$topFraction]);
                });
        });
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
