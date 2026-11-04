<?php

namespace App\Console\Commands;

use App\Services\Retro\RetroMailParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Суммы восстановленных КП и счетов из текстового слоя PDF, без GPT. В --dry-run
 * показывает, сколько разобралось и какая сумма итоговая. Почту не читает.
 *
 *   php artisan retro:totals --database=MyLift_retro --dry-run --limit=500
 *   php artisan retro:totals --database=MyLift_retro
 */
class RetroTotalsCommand extends Command
{
    protected $signature = 'retro:totals
        {--database= : Имя базы (обязательно)}
        {--dry-run : Только посчитать, ничего не записывать}
        {--limit=0 : Сколько документов взять, 0 — все}';

    protected $description = 'Заполняет суммы восстановленных КП и счетов из текста PDF';

    public function handle(): int
    {
        $database = (string) $this->option('database');
        if ($database === '') {
            $this->error('Укажите базу: --database=MyLift_retro');

            return self::FAILURE;
        }
        $connection = (string) config('database.default');
        config(["database.connections.$connection.database" => $database]);
        DB::purge($connection);

        $query = DB::table('outbound_quotes as q')
            ->join('email_attachments as a', 'a.id', '=', 'q.email_attachment_id')
            ->whereRaw("q.payload->>'retro' = 'true'")->whereNull('q.total_amount')
            ->whereRaw('a.filename ilike ?', ['%.pdf'])
            ->select(['q.id', 'q.request_id', 'q.document_type', 'q.document_number', 'q.payload', 'a.file_path', 'a.disk'])
            ->orderByRaw('md5(q.id::text)');
        if ((int) $this->option('limit') > 0) {
            $query->limit((int) $this->option('limit'));
        }
        $rows = $query->get();
        $this->line('Документов: '.$rows->count());

        $stats = [];
        $bump = function (string $key) use (&$stats) {
            $stats[$key] = ($stats[$key] ?? 0) + 1;
        };
        foreach ($rows as $i => $row) {
            $process = new Process(['pdftotext', '-layout', Storage::disk($row->disk)->path($row->file_path), '-']);
            $process->run();
            $totals = RetroMailParser::documentTotals($process->getOutput());
            $type = $row->document_type === 'outbound_invoice' ? 'счёт' : 'КП';
            $bump("$type всего");
            // Итог документа — «на сумму» из строки «Всего наименований». Проверено на 1 500 документах:
            // она всегда равна «Итого со скидкой», а без скидки — «Итого».
            $total = $totals['sum'] ?? $totals['discounted'] ?? $totals['subtotal'];
            if ($total === null) {
                $bump("$type не разобран");

                continue;
            }
            $bump("$type разобран");
            if ($totals['currency'] !== null) {
                $bump('валюта '.$totals['currency']);
            }
            if ($totals['sum'] !== null) {
                $close = fn (?float $a) => $a !== null && abs($a - $totals['sum']) < 0.011;
                $bump("$type: сумма = ".match (true) {
                    $close($totals['discounted']) => 'итого со скидкой',
                    $close($totals['subtotal']) => $totals['discounted'] !== null ? 'итого, скидка есть' : 'итого',
                    default => 'другое',
                });
            }
            if ($this->option('dry-run')) {
                continue;
            }
            $payload = json_decode((string) $row->payload, true) ?: [];
            $payload['retro_totals'] = $totals;
            DB::table('outbound_quotes')->where('id', $row->id)->update([
                'subtotal' => $totals['subtotal'],
                'vat_amount' => $totals['vat'],
                'total_amount' => $total,
                'currency' => $totals['currency'] ?? 'RUB',
                'prices_include_vat' => $totals['vat'] !== null,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'updated_at' => now(),
            ]);
            if ($row->document_type === 'outbound_invoice') {
                DB::table('invoices')->where('request_id', $row->request_id)->where('invoice_number', $row->document_number)
                    ->update(['amount_snapshot' => $total, 'updated_at' => now()]);
            }
            if ($i % 2000 === 0) {
                $this->line(date('H:i:s ').$i);
            }
        }
        ksort($stats);
        foreach ($stats as $key => $value) {
            $this->line(str_pad((string) $value, 7).$key);
        }

        return self::SUCCESS;
    }
}
