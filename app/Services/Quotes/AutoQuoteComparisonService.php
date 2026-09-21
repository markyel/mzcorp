<?php

namespace App\Services\Quotes;

use App\Enums\DetectorType;
use App\Models\OutboundQuote;
use App\Models\Quotation;
use App\Models\Request;
use App\Services\Quotations\QuotationService;

/**
 * Сличение автоматического КП с тем, что по заявке реально ушло клиенту.
 *
 * Смысл холостого прогона: увидеть расхождения ДО того, как автомат начнёт
 * отвечать сам. Расхождение расхождению рознь, поэтому различаем три вида и
 * называем их прямо:
 *
 *   · состав       — в документе другой набор позиций (менеджер добавил или убрал);
 *   · номенклатура — то же число позиций, но другие артикулы (подобрал замену);
 *   · цена         — позиции те же, отличается цена или количество.
 *
 * Первые два — это экспертиза менеджера поверх буквального запроса, и именно
 * они показывают границу автомата. Третье чаще означает скидку или ручную
 * правку цены и опаснее выглядит, чем есть.
 *
 * За «реально ушло» считаем в порядке убывания достоверности: наше КП из
 * системы, затем распознанный исходящий документ (КП, потом счёт) — документ
 * важнее слов в письме (см. [[invoiced-status-requires-recognized-invoice]]).
 */
class AutoQuoteComparisonService
{
    public function __construct(
        private readonly QuotationService $quotations,
    ) {}

    /** Цены считаем разными, если расходятся больше чем на процент. */
    public const PRICE_TOLERANCE = 0.01;

    public const KIND_NONE = 'none';

    public const KIND_SAME = 'same';

    public const KIND_PRICE = 'price';

    public const KIND_NOMENCLATURE = 'nomenclature';

    public const KIND_COMPOSITION = 'composition';

    public const LABELS = [
        self::KIND_NONE => 'документа нет',
        self::KIND_SAME => 'совпало',
        self::KIND_PRICE => 'другая цена',
        self::KIND_NOMENCLATURE => 'другая номенклатура',
        self::KIND_COMPOSITION => 'другой состав',
    ];

    /**
     * Сравнить предложение автомата с фактическим документом заявки.
     *
     * @param  array<int, array<string, mixed>>  $lines  строки автоматического КП
     * @return array{kind: string, label: string, document: ?array<string, mixed>, rows: array<int, array<string, mixed>>, total_actual: ?float}
     */
    public function compare(Request $request, array $lines): array
    {
        $actual = $this->actualDocument($request);
        if ($actual === null) {
            return [
                'kind' => self::KIND_NONE,
                'label' => self::LABELS[self::KIND_NONE],
                'document' => null,
                'rows' => array_map(fn ($l) => $this->row($l, null), $lines),
                'total_actual' => null,
            ];
        }

        // Приводим автомат к дате документа: каталог с тех пор мог переоцениться,
        // и тогда «другая цена» — это наша же переоценка, а не ошибка правила.
        $lines = $this->rewind($lines, $actual['date_raw'] ?? null);

        $auto = collect($lines)->keyBy(fn ($l) => AutoQuoteRuleService::normalize($l['sku']));
        $fact = collect($actual['lines'])->keyBy(fn ($l) => AutoQuoteRuleService::normalize($l['sku']));

        $rows = [];
        foreach ($auto as $key => $line) {
            $rows[] = $this->row($line, $fact[$key] ?? null);
        }
        // Позиции, которых у автомата нет вовсе — их добавил менеджер.
        foreach ($fact as $key => $line) {
            if (! $auto->has($key)) {
                $rows[] = $this->row(null, $line);
            }
        }

        return [
            'kind' => $this->kind($auto->all(), $fact->all(), $rows),
            'label' => self::LABELS[$this->kind($auto->all(), $fact->all(), $rows)],
            'document' => $actual,
            'rows' => $rows,
            'total_actual' => (float) $actual['total'],
        ];
    }

    /**
     * Пересчитать строки автомата на дату документа.
     *
     * Сравнение ретроспективное: менеджер выставлял КП по прайсу того дня, а у
     * нас в каталоге уже новая цена. Без перемотки список показывает «другая
     * цена» там, где на самом деле расхождения не было.
     *
     * Режим «себестоимость + наценка» перемотать нельзя — истории закупочных
     * цен мы не ведём; такие строки честно помечаем.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    public function rewind(array $lines, ?\DateTimeInterface $moment): array
    {
        if ($moment === null) {
            return $lines;
        }

        foreach ($lines as &$line) {
            $line['price_today'] = $line['unit_price'];

            if (($line['pricing_mode'] ?? 'standard') === 'cost_plus') {
                $line['price_rewound'] = false;
                $line['no_history'] = true;

                continue;
            }

            $then = AutoQuoteRuleService::priceAt(
                (string) $line['sku'],
                $moment,
                (float) ($line['catalog_price'] ?? 0),
                isset($line['price_min']) ? (float) $line['price_min'] : null,
            );

            $line['catalog_price_then'] = $then['price'];
            $line['price_rewound'] = $then['changed'];
            if (! $then['changed']) {
                continue;
            }

            $line['unit_price'] = $this->quotations->computeFinalUnitPrice(
                $then['price'],
                $then['price_min'],
                (float) ($line['discount_percent'] ?? 0),
            );
            $line['total'] = round($line['unit_price'] * max((float) $line['qty'], 0), 2);
        }

        return $lines;
    }

    /**
     * @param  array<string, array<string, mixed>>  $auto
     * @param  array<string, array<string, mixed>>  $fact
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function kind(array $auto, array $fact, array $rows): string
    {
        if (count($auto) !== count($fact)) {
            return self::KIND_COMPOSITION;
        }
        if (array_keys($auto) !== array_keys($fact)) {
            // Тот же размер, но другие артикулы — подобрана замена.
            return self::KIND_NOMENCLATURE;
        }
        foreach ($rows as $row) {
            if ($row['price_differs'] || $row['qty_differs']) {
                return self::KIND_PRICE;
            }
        }

        return self::KIND_SAME;
    }

    /**
     * @param  array<string, mixed>|null  $auto
     * @param  array<string, mixed>|null  $fact
     * @return array<string, mixed>
     */
    private function row(?array $auto, ?array $fact): array
    {
        $autoPrice = (float) ($auto['unit_price'] ?? 0);
        $factPrice = (float) ($fact['unit_price'] ?? 0);
        $autoQty = (float) ($auto['qty'] ?? 0);
        $factQty = (float) ($fact['qty'] ?? 0);

        return [
            'sku' => (string) ($auto['sku'] ?? $fact['sku'] ?? ''),
            'name' => (string) ($auto['name'] ?? $fact['name'] ?? ''),
            'auto' => $auto,
            'fact' => $fact,
            'only_auto' => $auto !== null && $fact === null,
            'only_fact' => $auto === null && $fact !== null,
            'price_differs' => $auto !== null && $fact !== null && $factPrice > 0
                && abs($autoPrice - $factPrice) / max($factPrice, 0.01) > self::PRICE_TOLERANCE,
            'qty_differs' => $auto !== null && $fact !== null && abs($autoQty - $factQty) > 0.001,
            'price_delta' => $auto !== null && $fact !== null && $factPrice > 0 ? $autoPrice - $factPrice : null,
        ];
    }

    /**
     * Что реально ушло клиенту: сначала наше КП из системы, затем распознанный
     * исходящий документ — КП важнее счёта, счёт важнее ничего.
     *
     * @return array{type: string, label: string, number: string, date: ?string, total: float, lines: array<int, array<string, mixed>>}|null
     */
    public function actualDocument(Request $request): ?array
    {
        $quotation = Quotation::query()
            ->where('request_id', $request->id)
            ->whereNotNull('sent_at')
            ->with('items')
            ->orderByDesc('version')
            ->first();

        if ($quotation !== null) {
            return [
                'type' => 'quotation',
                'label' => 'КП из системы',
                'number' => (string) $quotation->internal_code,
                'date' => $quotation->sent_at?->format('d.m.Y'),
                'date_raw' => $quotation->sent_at,
                'total' => (float) $quotation->total,
                'lines' => $quotation->items->map(fn ($i) => [
                    'sku' => (string) $i->snapshot_sku,
                    'name' => (string) $i->snapshot_name,
                    'qty' => (float) $i->qty,
                    'unit_price' => (float) $i->final_unit_price,
                    'total' => (float) $i->line_total,
                ])->all(),
            ];
        }

        // Тип документа — enum DetectorType: КП (полное или частичное) важнее
        // счёта, счёт важнее прочего.
        $outbound = OutboundQuote::query()
            ->where('request_id', $request->id)
            ->where('status', OutboundQuote::STATUS_MATCHED)
            ->with(['items.catalogItem:id,sku', 'emailMessage:id,sent_at'])
            ->orderByRaw("case
                when document_type like 'outbound_quotation%' then 0
                when document_type = 'outbound_invoice' then 1
                else 2 end")
            ->orderByDesc('id')
            ->first();

        if ($outbound === null || $outbound->items->isEmpty()) {
            return null;
        }

        $type = $outbound->document_type instanceof DetectorType
            ? $outbound->document_type->value
            : (string) $outbound->document_type;

        return [
            'type' => $type,
            'label' => $type === DetectorType::OutboundInvoice->value ? 'счёт менеджера' : 'КП менеджера',
            'number' => (string) $outbound->document_number,
            'date' => $outbound->document_date?->format('d.m.Y'),
            // Для перемотки цены нужна ОТМЕТКА ВРЕМЕНИ, а не дата документа:
            // `document_date` — это полночь, а импорт каталога проходит утром,
            // и КП, отправленное днём, откатывалось бы к вчерашней цене.
            'date_raw' => $outbound->emailMessage?->sent_at ?: ($outbound->created_at ?: $outbound->document_date),
            'total' => (float) $outbound->total_amount,
            'lines' => $outbound->items->map(fn ($i) => [
                'sku' => (string) ($i->catalogItem?->sku ?: $i->raw_article),
                'name' => (string) $i->raw_name,
                'qty' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'total' => (float) $i->line_total,
            ])->all(),
        ];
    }
}
