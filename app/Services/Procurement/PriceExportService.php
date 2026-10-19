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
 * валюта; в конце — выбранный поставщик позиции.
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

        $template = config('procurement.price_export.template_suppliers', []);
        $others = $latest->groupBy('name')->map->count()->sortDesc()->keys()->reject(fn ($n) => in_array($n, $template, true))->values()->all();
        $columns = array_values(array_unique([...$template, ...$others]));

        $choices = app(SupplierChoiceService::class)->forItems($latest->pluck('cid')->unique()->values()->all());
        $rows = $latest->groupBy('sku')->sortKeys();

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Лист1');
        $header = ['М-артикул'];
        foreach ($columns as $name) {
            array_push($header, $name, 'Дата предложения', 'Валюта');
        }
        $header[] = 'Выбранный поставщик';
        $sheet->fromArray($header, null, 'A1');

        $r = 2;
        foreach ($rows as $sku => $bySupplier) {
            $line = [$sku];
            $bySupplier = $bySupplier->keyBy('name');
            foreach ($columns as $name) {
                $p = $bySupplier->get($name);
                array_push($line, $p ? $p['price'] : null, $p ? ExcelDate::PHPToExcel($p['date']->copy()->startOfDay()) : null, $p ? $p['currency'] : null);
            }
            $choice = $choices->get($bySupplier->first()['cid']);
            $line[] = $choice ? ($names[$choice->supplier_key] ?? $choice->supplier_name) : null;
            $sheet->fromArray($line, null, 'A'.$r, true);
            // Принятое к закупке отклонение (аналог, замена…) — примечанием к ячейке цены.
            foreach ($columns as $i => $name) {
                $note = $bySupplier->get($name)['note'] ?? null;
                if ($note !== null) {
                    $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(2 + $i * 3).$r;
                    $sheet->getComment($cell)->getText()->createTextRun($note);
                    $sheet->getComment($cell)->setWidth('320pt');
                }
            }
            $r++;
        }
        // Даты — датами, ширина колонок.
        foreach ($columns as $i => $name) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(3 + $i * 3);
            $sheet->getStyle($col.'2:'.$col.max(2, $r - 1))->getNumberFormat()->setFormatCode('dd.mm.yyyy');
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
            ['Строки', 'M-артикул. Принятые к закупке аналог / восстановленное и т. п. — как цена самой позиции, с примечанием к ячейке цены: что предложено (вид, бренд, артикул, комментарий поставщика).'],
            ['Колонки', 'Все поставщики, приславшие цену: сначала поставщики шаблона в его порядке, затем остальные по числу артикулов. Названия — как в 1С (по закупкам), иначе как в справочнике.'],
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
            ->get(['ci.id as cid', 'ci.sku', 'so.price', 'so.currency', 'so.raw_quote', 'si.supplier_email', 'si.supplier_name', DB::raw('coalesce(em.sent_at, so.created_at) as at')]);
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
                'note' => ($o->kind ?? 'original') !== 'original' ? self::deviationNote($o) : null,
            ]);
        }

        return [$out, $skipped];
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
