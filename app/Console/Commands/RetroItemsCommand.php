<?php

namespace App\Console\Commands;

use App\Services\RequestItemParsingService;
use App\Services\Retro\RetroDocumentItemsParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Позиции из наших КП и счетов (PDF из 1С) без GPT: RetroDocumentItemsParser по
 * тексту pdftotext -layout. Документ принимается, только если сумма строк сошлась
 * с итогом. Строки ложатся в outbound_quote_items (артикул M… → каталог), позиции
 * заявки — из последнего разобранного КП, а без КП — из последнего счёта; доставка
 * и прочие услуги в позиции заявки не идут. Заявки, у которых позиции уже есть, не
 * трогаются. Без --apply только отчёт.
 *
 *   php artisan retro:items --database=MyLift_retro2
 *   php artisan retro:items --database=MyLift_retro2 --apply
 */
class RetroItemsCommand extends Command
{
    protected $signature = 'retro:items
        {--database= : Имя базы (обязательно)}
        {--apply : Записать; без флага только отчёт}
        {--limit=0 : Не больше стольких документов (для пробы)}';

    protected $description = 'Позиции КП, счетов и заявок из текста PDF, без GPT';

    private const SOURCES = ['outbound_quotation_full' => 'retro_quote', 'outbound_invoice' => 'retro_invoice'];

    public function handle(): int
    {
        $database = (string) $this->option('database');
        if ($database === '') {
            $this->error('Укажите базу: --database=MyLift_retro2');

            return self::FAILURE;
        }
        $connection = (string) config('database.default');
        config(["database.connections.$connection.database" => $database]);
        DB::purge($connection);
        $apply = (bool) $this->option('apply');

        $catalog = DB::table('catalog_items')->whereNotNull('sku')->pluck('id', 'sku')->all();
        $this->line(date('H:i:s ').'каталог: '.count($catalog).' позиций');

        $query = DB::table('outbound_quotes as q')->join('email_attachments as a', 'a.id', '=', 'q.email_attachment_id')
            ->whereIn('q.document_type', array_keys(self::SOURCES))
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('outbound_quote_items as i')->whereColumn('i.outbound_quote_id', 'q.id'))
            ->select('q.id', 'q.request_id', 'q.document_type', 'a.file_path', 'a.filename')->orderBy('q.id');
        if ((int) $this->option('limit') > 0) {
            $query->limit((int) $this->option('limit'));
        }

        $stats = [];
        $parsedByQuote = [];
        foreach ($query->cursor() as $q) {
            $type = $q->document_type;
            $path = storage_path('app/private/'.$q->file_path);
            if (! is_file($path) || ! str_ends_with(mb_strtolower((string) $q->filename), '.pdf')) {
                $stats[$type]['no_pdf'] = ($stats[$type]['no_pdf'] ?? 0) + 1;

                continue;
            }
            $process = new Process(['pdftotext', '-q', '-layout', $path, '-']);
            $process->setTimeout(60);
            $process->run();
            $result = RetroDocumentItemsParser::parse($process->getOutput());
            if (! $result['ok']) {
                $stats[$type]['not_parsed'] = ($stats[$type]['not_parsed'] ?? 0) + 1;
                if ($apply) {
                    DB::table('outbound_quotes')->where('id', $q->id)->update([
                        'parse_error' => sprintf('Ретрозагрузка: сумма строк %.2f не сошлась с итогом %s', $result['sum'], $result['total'] ?? '—'),
                    ]);
                }

                continue;
            }
            $stats[$type]['parsed'] = ($stats[$type]['parsed'] ?? 0) + 1;
            $stats[$type]['lines'] = ($stats[$type]['lines'] ?? 0) + count($result['items']);
            $rows = [];
            foreach ($result['items'] as $n => $item) {
                $catalogId = $item['article'] !== null ? ($catalog[$item['article']] ?? null) : null;
                $stats[$type]['to_catalog'] = ($stats[$type]['to_catalog'] ?? 0) + ($catalogId !== null ? 1 : 0);
                $rows[] = [
                    'outbound_quote_id' => $q->id,
                    'position' => $n + 1,
                    'raw_name' => mb_substr($item['name'] !== '' ? $item['name'] : '(без наименования)', 0, 1000),
                    'raw_article' => $item['article'],
                    'quantity' => $item['qty'],
                    'unit_measure' => $item['unit'] !== null ? mb_substr($item['unit'], 0, 32) : null,
                    'unit_price' => $item['price_discounted'] ?? $item['price'],
                    'base_unit_price' => $item['price'],
                    'discount_percent' => $item['discount'],
                    'line_total' => $item['sum'],
                    'matched_catalog_item_id' => $catalogId,
                    'match_source' => $catalogId !== null ? 'retro_article' : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            $parsedByQuote[$q->id] = ['request_id' => $q->request_id, 'type' => $type, 'rows' => $rows];
            if ($apply) {
                DB::transaction(function () use ($q, $rows) {
                    DB::table('outbound_quote_items')->insert($rows);
                    DB::table('outbound_quotes')->where('id', $q->id)->update(['parsed_at' => now(), 'parse_error' => null]);
                });
            }
        }
        foreach ($stats as $type => $s) {
            $this->line("  $type: ".json_encode($s));
        }

        $filled = $this->requestItems($apply, $catalog);
        $this->info(date('H:i:s ').($apply ? 'записано' : 'без --apply ничего не записано').'; заявок с позициями из документов: '.$filled);

        return self::SUCCESS;
    }

    /**
     * Позиции заявок без позиций: из последнего разобранного КП, иначе из последнего счёта.
     * Без --apply считаются по документам, разобранным в этом прогоне.
     */
    private function requestItems(bool $apply, array $catalog): int
    {
        $filled = 0;
        $requests = DB::table('requests as r')
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('request_items as ri')->whereColumn('ri.request_id', 'r.id'))
            ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('outbound_quotes as q')->whereColumn('q.request_id', 'r.id')
                ->whereIn('q.document_type', array_keys(self::SOURCES)))
            ->pluck('r.id');
        foreach ($requests->chunk(500) as $chunk) {
            $documents = DB::table('outbound_quotes as q')
                ->whereIn('q.request_id', $chunk->all())
                ->whereIn('q.document_type', array_keys(self::SOURCES))
                ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('outbound_quote_items as i')->whereColumn('i.outbound_quote_id', 'q.id'))
                ->orderByRaw("CASE q.document_type WHEN 'outbound_quotation_full' THEN 0 ELSE 1 END")
                ->orderByDesc('q.document_date')->orderByDesc('q.id')
                ->get(['q.id', 'q.request_id', 'q.document_type'])
                ->unique('request_id');
            foreach ($documents as $doc) {
                $lines = DB::table('outbound_quote_items')->where('outbound_quote_id', $doc->id)->orderBy('position')->get();
                $lines = $lines->reject(fn ($l) => RequestItemParsingService::isServiceItem($l->raw_name))->values();
                if ($lines->isEmpty()) {
                    continue;
                }
                $filled++;
                if (! $apply) {
                    continue;
                }
                DB::transaction(function () use ($doc, $lines) {
                    foreach ($lines as $n => $line) {
                        $id = DB::table('request_items')->insertGetId([
                            'request_id' => $doc->request_id,
                            'position' => $n + 1,
                            'parsed_name' => mb_substr($line->raw_name, 0, 255),
                            'parsed_article' => $line->raw_article,
                            'parsed_qty' => $line->quantity ?? 1,
                            'parsed_unit' => $line->unit_measure ?? 'шт.',
                            'catalog_item_id' => $line->matched_catalog_item_id,
                            'data_source' => self::SOURCES[$doc->document_type],
                            'status' => 'parsed',
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        DB::table('outbound_quote_items')->where('id', $line->id)->update(['matched_request_item_id' => $id]);
                    }
                });
            }
        }

        return $filled;
    }
}
