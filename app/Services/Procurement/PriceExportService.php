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
 * Выгрузка новых цен поставщиков в Excel под загрузку в 1С — по шаблону заказчика:
 * M-артикул, затем по каждому поставщику (названием, как в 1С) — цена, дата предложения,
 * валюта, комментарий (что важно знать о предложении: аналог, мин. партия, ступени цены,
 * срок, действует до, примечание поставщика); в конце — выбранный поставщик позиции.
 * Колонки без данных (поставщик шаблона без цен за период, никто не выбран) не выводятся.
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

    public function export(Carbon $from, Carbon $to, ?User $by): PriceExport
    {
        [$prices, $skipped] = $this->collect($from, $to);
        $names = $this->names1c($prices->pluck('key')->merge(
            app(SupplierChoiceService::class)->forItems($prices->pluck('cid')->unique()->values()->all())->pluck('supplier_key')
        )->filter()->unique()->values()->all());

        // По поставщику (как в 1С) и позиции — последняя цена периода.
        $latest = $prices->map(fn ($p) => $p + ['name' => $names[$p['key']] ?? $p['fallback']])
            ->sortBy('date')->keyBy(fn ($p) => $p['sku'].'|'.$p['name']);

        // Поставщики шаблона — в его порядке, но только с ценами за период; затем остальные.
        $template = config('procurement.price_export.template_suppliers', []);
        $withPrices = $latest->pluck('name')->unique();
        $others = $latest->groupBy('name')->map->count()->sortDesc()->keys()->reject(fn ($n) => in_array($n, $template, true))->values()->all();
        $columns = array_values(array_filter(array_unique([...$template, ...$others]), fn ($n) => $withPrices->contains($n)));

        $choices = app(SupplierChoiceService::class)->forItems($latest->pluck('cid')->unique()->values()->all());
        $rows = $latest->groupBy('sku')->sortKeys();
        $withChoice = $rows->contains(fn ($bySupplier) => $choices->has($bySupplier->first()['cid']));

        // По поставщику — 4 колонки: цена, дата предложения, валюта, комментарий.
        $per = 4;
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Лист1');
        $header = ['М-артикул'];
        foreach ($columns as $name) {
            array_push($header, $name, 'Дата предложения', 'Валюта', 'Комментарий');
        }
        if ($withChoice) {
            $header[] = 'Выбранный поставщик';
        }
        $sheet->fromArray($header, null, 'A1');

        $r = 2;
        foreach ($rows as $sku => $bySupplier) {
            $line = [$sku];
            $bySupplier = $bySupplier->keyBy('name');
            foreach ($columns as $name) {
                $p = $bySupplier->get($name);
                array_push($line, $p ? $p['price'] : null, $p ? ExcelDate::PHPToExcel($p['date']->copy()->startOfDay()) : null, $p ? $p['currency'] : null, $p ? ($p['comment'] ?? null) : null);
            }
            if ($withChoice) {
                $choice = $choices->get($bySupplier->first()['cid']);
                $line[] = $choice ? ($names[$choice->supplier_key] ?? $choice->supplier_name) : null;
            }
            $sheet->fromArray($line, null, 'A'.$r, true);
            $r++;
        }
        // Даты — датами, ширина колонок, комментарий — переносом.
        foreach ($columns as $i => $name) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(3 + $i * $per);
            $sheet->getStyle($col.'2:'.$col.max(2, $r - 1))->getNumberFormat()->setFormatCode('dd.mm.yyyy');
            $note = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(5 + $i * $per);
            $sheet->getColumnDimension($note)->setWidth(40);
            $sheet->getStyle($note.'2:'.$note.max(2, $r - 1))->getAlignment()->setWrapText(true);
        }
        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->freezePane('B2');

        $notes = $book->createSheet();
        $notes->setTitle('Пояснения');
        $notes->fromArray([
            ['Выгрузка новых цен поставщиков', ''],
            ['Период', $from->format('d.m.Y H:i').' – '.$to->format('d.m.Y H:i').' (по дате письма / предложения поставщика)'],
            ['Сформировано', now()->format('d.m.Y H:i').($by ? ' · '.$by->name : '')],
            ['', ''],
            ['Строки', 'M-артикул. Принятые к закупке аналог / восстановленное и т. п. — как цена самой позиции; что предложено — в комментарии.'],
            ['Колонки', 'Поставщики, приславшие цену за период: сначала поставщики шаблона в его порядке, затем остальные по числу артикулов; поставщики без цен не выводятся. Названия — как в 1С (по закупкам), иначе как в справочнике.'],
            ['Комментарий', 'Что важно знать о предложении: не оригинал (аналог, копия…) с брендом и артикулом, минимальная партия и ступени цены, срок поставки, до какой даты действует цена, примечание поставщика; для ответов письмом — фрагмент письма с ценой.'],
            ['Цена', 'Последняя цена поставщика по позиции за период; цена за единицу, в валюте поставщика.'],
            ['Выбранный поставщик', 'У кого закупаем позицию — выбор снабжения в «Снабжении».'],
            ['Не выгружено', $skipped.' ответ(ов) рассылки с аналогом / копией в тексте — их нужно разобрать по письму.'],
            ['Итого', ($r - 2).' артикулов, '.count(array_filter($columns, fn ($n) => $latest->contains('name', $n))).' поставщиков'],
        ], null, 'A1');
        $notes->getColumnDimension('A')->setWidth(22);
        $notes->getColumnDimension('B')->setWidth(110);
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
            'rows_count' => $r - 2,
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
            $parts[] = '«'.mb_substr($quote, 0, 300).'»';
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
