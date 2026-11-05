<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\RequestActivityType;
use App\Enums\RequestStatus;
use App\Services\Invoices\PaymentImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Оплаты из выгрузки 1С («Оплаты МЛ …») по восстановленной базе: счёт по номеру
 * (числовая часть «НФ00-011561» — как в PaymentImportService), счёт → оплачен или
 * частично оплачен, заявка → «Закрыто · успех» с датой оплаты (решение заказчика 05.11:
 * оплаченный счёт — успех). Пишет напрямую, без событий, уведомлений и задач в очереди:
 * живой импорт (InvoiceService::markPaid) реанимирует и уведомляет, а воркеры выключены.
 * Счета, которых нет в базе, — в CSV в журналах ретрозагрузки. Без --apply только отчёт.
 *
 *   php artisan retro:payments --database=MyLift_retro2 --file=/var/tmp/payments.xlsx
 *   php artisan retro:payments --database=MyLift_retro2 --file=/var/tmp/payments.xlsx --apply
 */
class RetroPaymentsCommand extends Command
{
    protected $signature = 'retro:payments
        {--database= : Имя базы (обязательно)}
        {--file= : Выгрузка оплат из 1С, xlsx}
        {--apply : Записать; без флага только отчёт}';

    protected $description = 'Отмечает оплаченные счета и закрывает заявки успехом по выгрузке оплат 1С';

    public function handle(PaymentImportService $import): int
    {
        $database = (string) $this->option('database');
        $file = (string) $this->option('file');
        if ($database === '' || ! is_file($file)) {
            $this->error('Укажите базу и файл: --database=MyLift_retro2 --file=/path/payments.xlsx');

            return self::FAILURE;
        }
        $connection = (string) config('database.default');
        config(["database.connections.$connection.database" => $database]);
        DB::purge($connection);
        $apply = (bool) $this->option('apply');

        $parsed = $import->parse($file);
        if ($parsed['error'] !== null) {
            $this->error($parsed['error']);

            return self::FAILURE;
        }
        $invoices = DB::table('invoices')->get(['id', 'request_id', 'invoice_number', 'status', 'paid_at'])
            ->groupBy(fn ($i) => $import->normalizeNumber((string) $i->invoice_number));

        $stats = ['rows' => count($parsed['rows']), 'paid' => 0, 'partial' => 0, 'already' => 0, 'unknown_after_launch' => 0, 'unknown_before_launch' => 0, 'requests_won' => 0];
        $unknown = [];
        $won = [];
        $tz = (string) config('app.timezone');
        foreach ($parsed['rows'] as $row) {
            $candidates = $row['number_int'] !== null ? $invoices->get($row['number_int']) : null;
            if ($candidates === null || $candidates->isEmpty()) {
                $old = $row['invoice_date'] !== null && $row['invoice_date'] < PaymentImportService::SYSTEM_LAUNCH_DATE;
                $stats[$old ? 'unknown_before_launch' : 'unknown_after_launch']++;
                $unknown[] = $row;

                continue;
            }
            $full = (int) $row['percent'] >= 100;
            $paidAt = Carbon::parse(($row['paid_date'] ?? $row['invoice_date'] ?? 'now').' 12:00:00', $tz);
            foreach ($candidates as $invoice) {
                if ($invoice->status === InvoiceStatus::Paid->value) {
                    $stats['already']++;
                } else {
                    $stats[$full ? 'paid' : 'partial']++;
                    if ($apply) {
                        DB::table('invoices')->where('id', $invoice->id)->update(array_filter([
                            'status' => ($full ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid)->value,
                            'paid_at' => $full ? $paidAt : null,
                            'partially_paid_at' => $full ? null : $paidAt,
                            'paid_amount' => $row['paid_sum'],
                            'updated_at' => now(),
                        ], fn ($v) => $v !== null));
                    }
                }
                // По заявке — последняя оплата: её дата становится датой закрытия.
                if (! isset($won[$invoice->request_id]) || $paidAt->greaterThan($won[$invoice->request_id]['at'])) {
                    $won[$invoice->request_id] = ['at' => $paidAt, 'number' => $invoice->invoice_number, 'row' => $row];
                }
            }
        }

        foreach ($won as $requestId => $payment) {
            $request = DB::table('requests')->where('id', $requestId)->first(['id', 'status', 'last_activity_at']);
            if ($request === null || $request->status === RequestStatus::ClosedWon->value) {
                continue;
            }
            $stats['requests_won']++;
            if (! $apply) {
                continue;
            }
            $row = $payment['row'];
            DB::transaction(function () use ($request, $payment, $row) {
                DB::table('requests')->where('id', $request->id)->update([
                    'status' => RequestStatus::ClosedWon->value,
                    'peak_status' => RequestStatus::ClosedWon->value,
                    'closed_at' => $payment['at'],
                    'closed_lost_reason' => null,
                    'closed_lost_comment' => null,
                    'last_activity_at' => $request->last_activity_at !== null && $request->last_activity_at > $payment['at']->toDateTimeString()
                        ? $request->last_activity_at : $payment['at'],
                    'last_activity_type' => RequestActivityType::ClosedWon->value,
                    'updated_at' => now(),
                ]);
                DB::table('request_state_changes')->insert([
                    'request_id' => $request->id,
                    'from_status' => $request->status,
                    'to_status' => RequestStatus::ClosedWon->value,
                    'by_user_id' => null,
                    'event' => 'retro_payment',
                    'comment' => sprintf('Оплата по счёту №%s от %s — по выгрузке оплат 1С (ретрозагрузка).',
                        $payment['number'], $payment['at']->format('d.m.Y')),
                    'payload' => json_encode([
                        'invoice_number' => $payment['number'],
                        'paid_sum' => $row['paid_sum'],
                        'percent' => $row['percent'],
                        'paid_date' => $row['paid_date'],
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $payment['at'],
                    'updated_at' => $payment['at'],
                ]);
            });
        }

        $this->info(json_encode($stats, JSON_UNESCAPED_UNICODE));
        $this->writeUnknown($unknown);
        $this->line($apply ? 'Записано.' : 'Без --apply ничего не записано.');

        return self::SUCCESS;
    }

    /** Оплаты без счёта в базе — в журналы ретрозагрузки, не в репозиторий: там клиенты. */
    private function writeUnknown(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $dir = storage_path('logs/retro');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir.'/payments-unknown-'.date('Ymd-His').'.csv';
        $handle = fopen($file, 'w');
        fputcsv($handle, ['number', 'invoice_date', 'paid_date', 'percent', 'paid_sum', 'client', 'manager']);
        foreach ($rows as $r) {
            fputcsv($handle, [$r['number_raw'] ?? $r['number_int'], $r['invoice_date'], $r['paid_date'], $r['percent'], $r['paid_sum'], $r['client'] ?? '', $r['manager'] ?? '']);
        }
        fclose($handle);
        $this->line('Без счёта в базе: '.count($rows).' → '.$file);
    }
}
