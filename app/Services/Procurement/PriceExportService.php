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
 * действует до, примечание поставщика, «закупаем здесь»).
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

    public const HEADER = ['Артикул', 'Поставщик', 'Цена', 'Дата', 'Валюта', 'Комментарий'];

    public function export(Carbon $from, Carbon $to, ?User $by): PriceExport
    {
        [$prices, $skipped] = $this->collect($from, $to);
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
            $comment = implode('; ', array_filter([
                $p['code'] === null ? 'поставщик '.$p['name'].' (нет кода 1С)' : null,
                $choice && $p['key'] !== null && $choice->supplier_key === $p['key'] ? 'закупаем здесь (выбор снабжения)' : null,
                $p['comment'] ?? null,
            ]));
            // Без кавычек: в комментарии их не нужно (загрузка в 1С), в т. ч. из текста поставщика.
            $comment = trim((string) preg_replace('/\s{2,}/u', ' ', str_replace(['«', '»', '"', '“', '”', '„'], '', $comment)));
            $sheet->fromArray([$p['sku'], $p['code'], $p['price'], ExcelDate::PHPToExcel($p['date']->copy()->startOfDay()), $p['currency'], $comment !== '' ? $comment : null], null, 'A'.$r, true);
            // Коды — текстом: «00-000523» не должен стать числом.
            $sheet->setCellValueExplicit('B'.$r, (string) $p['code'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $r++;
        }
        $last = max(2, $r - 1);
        $sheet->getStyle('D2:D'.$last)->getNumberFormat()->setFormatCode('dd.mm.yyyy');
        $sheet->getStyle('F2:F'.$last)->getAlignment()->setWrapText(true);
        foreach (['A' => 12, 'B' => 14, 'C' => 12, 'D' => 12, 'E' => 8, 'F' => 70] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->freezePane('A2');

        $noCode = $latest->whereNull('code')->pluck('name')->unique()->values();
        $notes = $book->createSheet();
        $notes->setTitle('Пояснения');
        $notes->fromArray([
            ['Выгрузка новых цен поставщиков', ''],
            ['Период', $from->format('d.m.Y H:i').' – '.$to->format('d.m.Y H:i').' (по дате письма / предложения поставщика)'],
            ['Сформировано', now()->format('d.m.Y H:i').($by ? ' · '.$by->name : '')],
            ['', ''],
            ['Артикул', 'M-артикул позиции. Строка — позиция × поставщик: последняя цена поставщика по позиции за период, за единицу, в валюте поставщика. Принятые к закупке аналог / восстановленное и т. п. — как цена самой позиции; что предложено — в комментарии.'],
            ['Поставщик', 'Код контрагента из справочника 1С УНФ. Нет кода — поставщик не привязан к контрагенту 1С: его название в комментарии, привязать можно в «Выгрузке цен».'],
            ['Комментарий', 'Что важно знать о предложении: не оригинал (аналог, копия…) с брендом и артикулом, минимальная партия и ступени цены, срок поставки, до какой даты действует цена, примечание поставщика, закупаем здесь; для ответов письмом — фрагмент письма с ценой.'],
            ['Не выгружено', $skipped.' ответ(ов) рассылки с аналогом / копией в тексте — их нужно разобрать по письму.'],
            ['Без кода 1С', $noCode->isEmpty() ? '—' : $noCode->implode(', ')],
            ['Итого', ($r - 2).' строк, '.$latest->pluck('sku')->unique()->count().' артикулов, '.$latest->pluck('name')->unique()->count().' поставщиков'],
        ], null, 'A1');
        $notes->getColumnDimension('A')->setWidth(22);
        $notes->getColumnDimension('B')->setWidth(110);
        $notes->getStyle('B1:B12')->getAlignment()->setWrapText(true);
        $book->setActiveSheetIndex(0);

        $disk = config('procurement.price_export.disk', 'local');
        $path = trim(config('procurement.price_export.directory', 'exports/prices'), '/').'/Цены поставщиков '.$from->format('d.m').'-'.$to->format('d.m.Y').' '.now()->format('His').'.xlsx';
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
            $at = $o->remote_updated_at ?? $o->created_at;
            $out->push([
                'cid' => (int) $o->catalog_item_id, 'sku' => (string) $o->catalogItem->sku,
                'key' => $o->supplier_key, 'fallback' => (string) ($o->supplier_name ?: $o->supplier_key),
                'price' => (float) $o->price, 'currency' => $o->currency,
                'date' => Carbon::parse($at),
                'comment' => self::offerComment($o),
            ]);
        }

        return [$out, $skipped];
    }

    /**
     * Комментарий к предложению QuoteParts: не оригинал (что именно), мин. партия и ступени
     * цены, срок поставки, действует до, примечание поставщика. Null — сказать нечего.
     */
    public static function offerComment(QuotePartsOffer $o): ?string
    {
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', ' '), '0'), ',');
        $parts = [];
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
            $parts[] = 'срок '.(int) $o->lead_time_days.' дн.';
        }
        if ($o->valid_until !== null) {
            $parts[] = 'действует до '.Carbon::parse($o->valid_until)->format('d.m.Y');
        }
        // Примечание поставщика — если его ещё нет в описании отклонения.
        $note = trim((string) $o->note);
        if ($note !== '' && ! (($o->kind ?? 'original') !== 'original' && str_contains(self::deviationNote($o), $note))) {
            $parts[] = $note;
        }

        return $parts === [] ? null : mb_substr(implode('; ', $parts), 0, 1000);
    }

    /** Комментарий к ответу письмом: срок действия цены и фрагмент письма с ценой. */
    public static function mailComment(?string $validUntil, ?string $quote): ?string
    {
        $parts = [];
        $quote = trim((string) preg_replace('/\s+/u', ' ', (string) $quote));
        // Срок действия — только если его нет во фрагменте письма: разбор кладёт сюда и срок
        // подготовки («срок подготовки 3-5 дней»), тогда подпись «действует» была бы неверной.
        $valid = trim((string) $validUntil);
        if ($valid !== '' && mb_stripos($quote, $valid) === false) {
            $parts[] = 'действует: '.$valid;
        }
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
