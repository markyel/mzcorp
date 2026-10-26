<?php

namespace App\Services\Procurement;

use App\Models\PriceExport;
use App\Models\QuotePartsOffer;
use App\Models\Supplier;
use App\Models\SupplierOrganization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Выгрузка новых цен поставщиков в Excel под загрузку в 1С — 6 колонок, строка на пару
 * «позиция × поставщик»: Артикул (M-артикул), Поставщик (код контрагента 1С), Цена, Дата, Валюта,
 * комментарий (что важно знать о предложении: аналог, мин. партия, ступени цены, срок,
 * примечание поставщика) и «Выбор» — 1 у предложения, выбранного снабжением к закупке.
 *
 * Код поставщика — из справочника контрагентов 1С (SupplierOnecCodeService). Поставщик без
 * кода выгружается с пустым кодом и своим названием в комментарии; список таких — на листе
 * «Пояснения», привязать их можно в «Выгрузке цен».
 *
 * Правила:
 *  - все поставщики, приславшие цену за период (письмом на рассылку или через QuoteParts);
 *  - по поставщику и позиции — последняя цена периода;
 *  - отклонения (аналог, копия, восстановленное, замена) — только принятые к закупке, и
 *    тогда как цена самой позиции; ответ рассылки, где поставщик сам пишет «аналог»/«копия»,
 *    не выгружается (разобрать его можно только в письме) — их число на листе «Пояснения»;
 *  - позиции без M-артикула (не из каталога) не берём.
 */
class PriceExportService
{
    private const DEVIATION_WORDS = '/(аналог|analog|alternative|equivalent|replacement|копи[яи]|\bcopy\b|replica|совместим|compatible|восстановлен|refurbish)/iu';

    // Порядок колонок — по шаблону заказчика (23.10.2026): «Выбор» перед «Комментарием».
    public const HEADER = ['Артикул', 'Поставщик', 'Цена', 'Дата', 'Валюта', 'Выбор', 'Комментарий'];

    public function export(Carbon $from, Carbon $to, ?User $by): PriceExport
    {
        [$prices, $skipped] = $this->collect($from, $to);

        return $this->write($prices, $skipped, $from, $to, $by, PriceExport::KIND_PERIOD);
    }

    /**
     * Выгрузка по списку позиций «Актуализации цен»: все действующие цены поставщиков по этим
     * позициям, выбранное предложение — 1 в колонке «Выбор». У поставщика по позиции одна
     * строка: выбранное предложение, иначе оригинал, иначе последнее (копию 3 USD рядом с
     * оригиналом 6 USD того же поставщика в 1С не грузим, пока её не выбрали).
     *
     * @param  Collection<int, QuotePartsOffer>  $offers
     */
    public function exportOffers(Collection $offers, ?User $by): PriceExport
    {
        $offers = $offers->filter(fn (QuotePartsOffer $o) => $o->catalogItem !== null && (float) $o->price > 0 && $o->countsAsItemPrice());
        $chosen = app(SupplierChoiceService::class)->forItems($offers->pluck('catalog_item_id')->unique()->values()->all())
            ->filter(fn ($c) => $c->source === 'quoteparts')->map(fn ($c) => (int) $c->offer_ref);

        $prices = $offers->groupBy(fn (QuotePartsOffer $o) => $o->catalog_item_id.'|'.$o->supplier_key)
            ->map(fn (Collection $g) => $g->first(fn ($o) => ($chosen[$o->catalog_item_id] ?? null) === (int) $o->id)
                ?? $g->where('kind', 'original')->sortByDesc('remote_updated_at')->first()
                ?? $g->sortByDesc('remote_updated_at')->first())
            ->map(fn (QuotePartsOffer $o) => self::quotePartsPrice($o))
            ->values();

        $from = $prices->min('date');

        return $this->write($prices, 0, $from ? Carbon::parse($from) : now(), now(), $by, PriceExport::KIND_POSITIONS);
    }

    /**
     * Цены по заявке (вкладка «Поставщики»): строки готовит RequestSupplierOverview::exportPrices —
     * письма и QuoteParts, по позициям с выбранной ценой.
     *
     * @param  Collection<int, array{cid:int, sku:string, key:?string, fallback:string, price:float, currency:?string, date:Carbon, comment:?string}>  $prices
     */
    public function exportRequestPrices(Collection $prices, string $requestCode, ?User $by): PriceExport
    {
        $from = $prices->min('date');

        return $this->write($prices, 0, $from ? Carbon::parse($from) : now(), now(), $by, PriceExport::KIND_REQUEST, $requestCode);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $prices
     */
    private function write(Collection $prices, int $skipped, Carbon $from, Carbon $to, ?User $by, string $kind, ?string $requestCode = null): PriceExport
    {
        // По списку «Актуализации» и по заявке — одинаково: позиции с выбранной ценой, не период.
        $positions = in_array($kind, [PriceExport::KIND_POSITIONS, PriceExport::KIND_REQUEST], true);
        $keys = $prices->pluck('key')->filter()->unique()->values()->all();
        $names = $this->names1c($keys);
        $codes = app(SupplierOnecCodeService::class);
        $codeByKey = $codes->codesFor($keys, $names);

        // По поставщику и позиции — последняя цена периода.
        $latest = $prices->map(function ($p) use ($names, $codeByKey, $codes) {
            $name = $p['key'] !== null ? ($names[$p['key']] ?? $p['fallback']) : $p['fallback'];
            $code = $p['key'] !== null ? ($codeByKey[$p['key']]['code'] ?? null) : $codes->codeForName($p['fallback']);

            return $p + ['name' => $name, 'code' => $code];
        })->sortBy('date')->keyBy(fn ($p) => $p['sku'].'|'.($p['key'] ?? 'name:'.$p['name']))->sortBy(fn ($p) => [$p['sku'], $p['name']])->values();

        $choices = app(SupplierChoiceService::class)->forItems($latest->pluck('cid')->unique()->values()->all());

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Лист1');
        $sheet->fromArray(self::HEADER, null, 'A1');

        $r = 2;
        foreach ($latest as $p) {
            $choice = $choices->get($p['cid']);
            // Выбранное снабжением предложение («Закупаем здесь») — 1 в колонке «Выбор».
            $chosen = $choice && $p['key'] !== null && $choice->supplier_key === $p['key'];
            $comment = implode('; ', array_filter([
                $p['code'] === null ? 'поставщик '.$p['name'].' (нет кода 1С)' : null,
                $p['comment'] ?? null,
            ]));
            // Без кавычек: в комментарии их не нужно (загрузка в 1С), в т. ч. из текста поставщика.
            $comment = trim((string) preg_replace('/\s{2,}/u', ' ', str_replace(['«', '»', '"', '“', '”', '„'], '', $comment)));
            $sheet->fromArray([$p['sku'], $p['code'], $p['price'], ExcelDate::PHPToExcel($p['date']->copy()->startOfDay()), $p['currency'], $chosen ? 1 : null, $comment !== '' ? $comment : null], null, 'A'.$r, true);
            // Коды — текстом: «00-000523» не должен стать числом.
            $sheet->setCellValueExplicit('B'.$r, (string) $p['code'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $r++;
        }
        $last = max(2, $r - 1);
        // Дата — встроенным форматом даты Excel (код 14): категория «Дата», в русском Excel — 19.10.2026;
        // пользовательский dd.mm.yyyy Excel относит к «(все форматы)», и загрузчик дату не узнаёт.
        $sheet->getStyle('D2:D'.$last)->getNumberFormat()->setBuiltInFormatCode(14);
        $sheet->getStyle('G2:G'.$last)->getAlignment()->setWrapText(true);
        foreach (['A' => 12, 'B' => 14, 'C' => 12, 'D' => 12, 'E' => 8, 'F' => 8, 'G' => 70] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->freezePane('A2');

        $noCode = $latest->whereNull('code')->pluck('name')->unique()->values();
        $notes = $book->createSheet();
        $notes->setTitle('Пояснения');
        $notes->fromArray([
            ['Выгрузка новых цен поставщиков', ''],
            $positions
                ? ['Позиции', ($requestCode !== null ? 'Заявка '.$requestCode : 'Список выгрузки «Актуализация цен»').': позиции с выбранным предложением — все действующие цены поставщиков по ним, выбранное — 1 в колонке «Выбор».']
                : ['Период', $from->format('d.m.Y H:i').' – '.$to->format('d.m.Y H:i').' (по дате письма / предложения поставщика)'],
            ['Сформировано', now()->format('d.m.Y H:i').($by ? ' · '.$by->name : '')],
            ['', ''],
            ['Артикул', 'M-артикул позиции. Строка — позиция × поставщик: последняя цена поставщика по позиции за период, за единицу, в валюте поставщика. Принятые к закупке аналог / восстановленное и т. п. — как цена самой позиции; что предложено — в комментарии.'],
            ['Поставщик', 'Код контрагента из справочника 1С УНФ. Нет кода — поставщик не привязан к контрагенту 1С: его название в комментарии, привязать можно в «Выгрузке цен».'],
            ['Выбор', '1 — предложение, выбранное снабжением к закупке («Закупаем здесь»); у остальных поставщиков пусто.'],
            ['Комментарий', 'Что важно знать о предложении: не оригинал (аналог, копия…) с брендом и артикулом, минимальная партия и ступени цены, срок поставки, примечание поставщика; для ответов письмом — фрагмент письма с ценой.'],
            $positions
                ? ['Не выгружено', 'Копии и аналоги, которые не выбраны к закупке; предложения, отмеченные «Игнорировать».']
                : ['Не выгружено', $skipped.' ответ(ов) рассылки с аналогом / копией в тексте — их нужно разобрать по письму.'],
            ['Без кода 1С', $noCode->isEmpty() ? '—' : $noCode->implode(', ')],
            ['Итого', ($r - 2).' строк, '.$latest->pluck('sku')->unique()->count().' артикулов, '.$latest->pluck('name')->unique()->count().' поставщиков'],
        ], null, 'A1');
        $notes->getColumnDimension('A')->setWidth(22);
        $notes->getColumnDimension('B')->setWidth(110);
        $notes->getStyle('B1:B13')->getAlignment()->setWrapText(true);
        $book->setActiveSheetIndex(0);

        $disk = config('procurement.price_export.disk', 'local');
        $path = trim(config('procurement.price_export.directory', 'exports/prices'), '/').'/Цены поставщиков '.match (true) {
            $requestCode !== null => $requestCode.' '.now()->format('d.m.Y His'),
            $positions => 'актуализация '.now()->format('d.m.Y His'),
            default => $from->format('d.m').'-'.$to->format('d.m.Y').' '.now()->format('His'),
        }.'.xlsx';
        $tmp = tempnam(sys_get_temp_dir(), 'px');
        (new Xlsx($book))->save($tmp);
        Storage::disk($disk)->put($path, (string) file_get_contents($tmp));
        @unlink($tmp);

        return PriceExport::create([
            'from_at' => $from,
            'to_at' => $to,
            'rows_count' => $latest->pluck('sku')->unique()->count(),
            'suppliers_count' => $latest->pluck('name')->unique()->count(),
            'skipped_count' => $skipped,
            'file_path' => $path,
            'created_by_user_id' => $by?->id,
            'kind' => $kind,
        ]);
    }
    /**
     * Цены периода из обоих каналов.
     *
     * @return array{0: Collection<int, array{cid:int, sku:string, key:?string, fallback:string, price:float, currency:?string, date:Carbon}>, 1: int}
     */
    public function collect(Carbon $from, Carbon $to): array
    {
        $out = collect();
        $skipped = 0;

        // Ответы на рассылку (письма поставщиков, разобранные парсером).
        $mail = DB::table('supplier_offers as so')
            ->join('supplier_inquiry_items as sii', 'sii.id', '=', 'so.supplier_inquiry_item_id')
            ->join('supplier_inquiries as si', 'si.id', '=', 'sii.supplier_inquiry_id')
            ->leftJoin('request_items as ri', 'ri.id', '=', 'sii.request_item_id')
            ->leftJoin('email_messages as em', 'em.id', '=', 'so.email_message_id')
            ->join('catalog_items as ci', 'ci.id', '=', DB::raw('coalesce(sii.catalog_item_id, ri.catalog_item_id)'))
            ->where('so.outcome', 'quoted')->where('so.price', '>', 0)
            ->whereBetween(DB::raw('coalesce(em.sent_at, so.created_at)'), [$from, $to])
            ->get(['ci.id as cid', 'ci.sku', 'so.price', 'so.currency', 'so.raw_quote', 'so.valid_until_text', 'si.supplier_email', 'si.supplier_name', DB::raw('coalesce(em.sent_at, so.created_at) as at')]);
        $keys = SupplierChoiceService::keysByEmail($mail->pluck('supplier_email'));
        foreach ($mail as $m) {
            if (preg_match(self::DEVIATION_WORDS, (string) $m->raw_quote)) {
                $skipped++;

                continue;
            }
            $out->push([
                'cid' => (int) $m->cid, 'sku' => (string) $m->sku,
                'key' => $keys[mb_strtolower(trim((string) $m->supplier_email))] ?? null,
                'fallback' => (string) ($m->supplier_name ?: $m->supplier_email),
                'price' => (float) $m->price, 'currency' => $m->currency ?: 'RUB',
                'date' => Carbon::parse($m->at),
                'comment' => self::mailComment($m->valid_until_text, $m->raw_quote),
            ]);
        }

        // QuoteParts: оригинал — сразу, отклонение — когда принято к закупке (тогда и попадает в период).
        $qp = QuotePartsOffer::query()->with('catalogItem:id,sku')->whereNotNull('catalog_item_id')->where('price', '>', 0)
            ->where(fn ($q) => $q->whereBetween('remote_updated_at', [$from, $to])->orWhereBetween('reviewed_at', [$from, $to]))
            ->get();
        foreach ($qp as $o) {
            if (! $o->countsAsItemPrice() || $o->catalogItem === null) {
                continue;
            }
            $out->push(self::quotePartsPrice($o));
        }

        return [$out, $skipped];
    }

    /**
     * Строка выгрузки по предложению QuoteParts.
     *
     * @return array{cid:int, sku:string, key:?string, fallback:string, price:float, currency:?string, date:Carbon, comment:?string}
     */
    private static function quotePartsPrice(QuotePartsOffer $o): array
    {
        return [
            'cid' => (int) $o->catalog_item_id, 'sku' => (string) $o->catalogItem->sku,
            'key' => $o->supplier_key, 'fallback' => (string) ($o->supplier_name ?: $o->supplier_key),
            'price' => (float) $o->price, 'currency' => $o->currency,
            'date' => Carbon::parse($o->remote_updated_at ?? $o->created_at),
            // Поправленный снабжением комментарий — как есть, иначе собранный.
            'comment' => $o->export_comment ?? self::offerComment($o),
        ];
    }

    /**
     * Комментарий к предложению QuoteParts: не оригинал (что именно), мин. партия и ступени
     * цены, срок поставки, примечание поставщика (срок действия цены — нет). Null — сказать нечего.
     */
    public static function offerComment(QuotePartsOffer $o): ?string
    {
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', ' '), '0'), ',');
        $parts = [];
        // Цена агента (контрагент в 1С — агент): от какого реального поставщика.
        if (trim((string) $o->origin_supplier) !== '') {
            $parts[] = 'поставщик '.trim((string) $o->origin_supplier);
        }
        if (($o->kind ?? 'original') !== 'original') {
            $parts[] = self::deviationNote($o);
        }
        $tiers = collect((array) ($o->tiers ?? []))->filter(fn ($t) => isset($t['price']))->sortBy(fn ($t) => (float) ($t['min_qty'] ?? 1))->values();
        $first = (float) ($tiers->first()['min_qty'] ?? 1);
        if ($first > 1) {
            $parts[] = 'мин. партия '.$num($first);
        }
        if ($tiers->count() > 1) {
            $parts[] = 'цена по партиям: '.$tiers->map(fn ($t) => 'от '.$num($t['min_qty'] ?? 1).' — '.$num($t['price']))->implode('; ');
        }
        if ($o->lead_time_days !== null) {
            $parts[] = 'срок поставки '.(int) $o->lead_time_days.' дн.';
        }
        // Примечание поставщика — если его ещё нет в описании отклонения.
        $note = trim((string) $o->note);
        if ($note !== '' && ! (($o->kind ?? 'original') !== 'original' && str_contains(self::deviationNote($o), $note))) {
            $parts[] = $note;
        }

        return $parts === [] ? null : mb_substr(implode('; ', $parts), 0, 1000);
    }

    /** Комментарий к ответу письмом: фрагмент письма с ценой (срок действия цены — нет). */
    public static function mailComment(?string $validUntil, ?string $quote): ?string
    {
        $parts = [];
        $quote = trim((string) preg_replace('/\s+/u', ' ', (string) $quote));
        // Срок действия цены в комментарий не пишем (решение 21.10): в 1С он не нужен.
        if ($quote !== '') {
            $parts[] = mb_substr($quote, 0, 300);
        }

        return $parts === [] ? null : implode('; ', $parts);
    }

    /** «Аналог: Fermator FR-60 — комментарий поставщика» — что вместо оригинала. */
    public static function deviationNote(QuotePartsOffer $o): string
    {
        $what = trim(($o->brand ?? '').' '.($o->part_number ?? ''));

        return mb_substr(QuotePartsOffer::kindLabel($o->kind).' (не оригинал)'.($what !== '' ? ': '.$what : '')
            .($o->product_name ? ', '.$o->product_name : '').($o->note ? ' — '.$o->note : ''), 0, 500);
    }

    /**
     * Названия поставщиков, как в 1С: по закупкам его адресов (самое частое), иначе из справочника.
     *
     * @param  list<string>  $keys  org:{id} / sup:{id} / email:{адрес}
     * @return array<string, string>
     */
    public function names1c(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');
            $supplierIds = match ($kind) {
                'org' => Supplier::query()->where('supplier_organization_id', (int) $id)->pluck('id')->all(),
                'sup' => [(int) $id],
                default => [],
            };
            $name = $supplierIds === [] ? null : DB::table('catalog_supplier_prices')->whereIn('supplier_id', $supplierIds)
                ->selectRaw('supplier_name_1c, count(*) c')->groupBy('supplier_name_1c')->orderByDesc('c')->value('supplier_name_1c');
            $name ??= match ($kind) {
                'org' => SupplierOrganization::query()->whereKey((int) $id)->value('name'),
                'sup' => Supplier::query()->whereKey((int) $id)->value('name') ?: Supplier::query()->whereKey((int) $id)->value('email'),
                default => $id,
            };
            if ($name) {
                $out[$key] = $name;
            }
        }

        return $out;
    }
}
