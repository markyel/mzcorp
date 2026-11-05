<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\Invoices\PaymentImportService;
use App\Services\Retro\RetroAssembler;
use App\Services\Retro\RetroIndex;
use App\Services\Retro\RetroMailParser;
use App\Services\Retro\RetroWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Заявки из цепочек без номера (RetroAssembler::assembleUnnumbered): переписка с клиентом
 * шла мимо CRM, наш КП или счёт в ней есть, а номер заявки ни в одно письмо не попал и
 * утерян. Номер — из отдельного временного диапазона «R-2026-0001…» (решение заказчика
 * 05.11), чтобы такие заявки отделялись от опознанных; в parsing_meta — number_lost.
 * Дописывает в собранную базу (RetroWriter), цепочки, письма которых уже в базе, пропускает.
 * После записи прогнать retro:hydrate, retro:attachments, retro:totals, retro:items,
 * retro:payments. Без --apply только отчёт и список в журналах ретрозагрузки.
 *
 *   php artisan retro:unnumbered --database=MyLift_retro2
 *   php artisan retro:unnumbered --database=MyLift_retro2 --apply
 *   php artisan retro:unnumbered --database=MyLift_retro2 --paid=/path/payments.xlsx   # только с оплаченным счётом
 */
class RetroUnnumberedCommand extends Command
{
    protected $signature = 'retro:unnumbered
        {--database= : Имя базы (обязательно)}
        {--paid= : Выгрузка оплат 1С: только цепочки с оплаченным счётом}
        {--apply : Записать; без флага только отчёт}';

    protected $description = 'Заявки R-… из цепочек с нашим КП или счётом, где номер заявки утерян';

    public function handle(RetroWriter $writer, PaymentImportService $payments): int
    {
        $database = (string) $this->option('database');
        if ($database === '') {
            $this->error('Укажите базу: --database=MyLift_retro2');

            return self::FAILURE;
        }
        $connection = (string) config('database.default');
        config(["database.connections.$connection.database" => $database]);
        DB::purge($connection);

        $paid = null;
        if ($this->option('paid')) {
            $parsed = $payments->parse((string) $this->option('paid'));
            if ($parsed['error'] !== null) {
                $this->error($parsed['error']);

                return self::FAILURE;
            }
            $paid = array_flip(array_filter(array_column($parsed['rows'], 'number_int')));
        }

        $this->line(date('H:i:s ').'читаю индекс и собираю');
        $index = new RetroIndex((string) config('retro.index_path'), (int) config('retro.year'), (int) config('retro.max_legacy_number'));
        $handlers = User::query()->role(Role::requestHandlerRoles())->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();
        $assembler = new RetroAssembler(config('retro'), $handlers, time());
        $result = $assembler->assemble($index->load());
        $rows = $result['rows'];

        // Письма, которые уже в базе: такую цепочку не трогаем (повторный запуск безопасен).
        $known = DB::table('email_messages as e')->join('mailboxes as m', 'm.id', '=', 'e.mailbox_id')
            ->whereNotNull('e.imap_uid')->selectRaw("lower(m.email) || '|' || e.folder || '|' || e.imap_uid as k")
            ->pluck('k')->flip()->all();
        $year = (int) config('retro.year');
        $last = (int) DB::table('requests')->where('internal_code', 'like', "R-$year-%")
            ->selectRaw("max(cast(split_part(internal_code, '-', 3) as integer)) as n")->value('n');

        $requests = $assembler->assembleUnnumbered($rows, $last + 1, function (array $chain) use ($known, $paid) {
            foreach ($chain as $row) {
                if (isset($known[mb_strtolower($row['box']).'|'.$row['folder'].'|'.$row['uid']])) {
                    return false;
                }
            }
            if ($paid === null) {
                return true;
            }
            foreach ($chain as $row) {
                foreach ($row['names'] as $name) {
                    $document = RetroMailParser::documentFromFilename($name);
                    if ($document !== null && $document['type'] === 'invoice' && isset($paid[(int) $document['number']])) {
                        return true;
                    }
                }
            }

            return false;
        });

        $this->report($requests);
        if (! $this->option('apply') || $requests === []) {
            $this->line($requests === [] ? 'Новых цепочек нет.' : 'Без --apply ничего не записано.');

            return self::SUCCESS;
        }

        $unseen = [];
        foreach ($index->mailboxes() as $box) {
            $unseen[$box] = $index->unseen($box);
        }
        $this->line(date('H:i:s ').'записываю в базу '.$database);
        $counts = $writer->write(['requests' => $requests, 'rows' => $rows], $unseen, (int) config('services.invoices.default_validity_business_days', 5));
        DB::table('requests')->whereIn('internal_code', array_column($requests, 'code'))
            ->update(['parsing_meta' => DB::raw("coalesce(parsing_meta::jsonb, '{}'::jsonb) || '{\"number_lost\": true}'::jsonb")]);
        foreach ($counts as $key => $value) {
            $this->line("  $key: $value");
        }
        $this->info(date('H:i:s ').'готово. Дальше: retro:hydrate, retro:attachments, retro:totals, retro:items, retro:payments');

        return self::SUCCESS;
    }

    private function report(array $requests): void
    {
        $status = [];
        $assigned = [];
        foreach ($requests as $r) {
            $status[$r['status']->value] = ($status[$r['status']->value] ?? 0) + 1;
            $assigned[$r['assigned'] ?? 'без ответственного'] = ($assigned[$r['assigned'] ?? 'без ответственного'] ?? 0) + 1;
        }
        arsort($status);
        arsort($assigned);
        $codes = array_column($requests, 'code');
        $this->info(sprintf('Заявок без номера: %d (%s … %s), с клиентом: %d, КП: %d, счетов: %d',
            count($requests), $codes[0] ?? '—', end($codes) ?: '—',
            count(array_filter($requests, fn ($r) => $r['client_email'] !== null)),
            array_sum(array_map(fn ($r) => count($r['quotes']), $requests)),
            array_sum(array_map(fn ($r) => count($r['invoices']), $requests))));
        $this->line('Статусы: '.json_encode($status, JSON_UNESCAPED_UNICODE));
        $this->line('Ответственные: '.json_encode($assigned, JSON_UNESCAPED_UNICODE));

        $dir = storage_path('logs/retro');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir.'/unnumbered-'.date('Ymd-His').'.csv';
        $handle = fopen($file, 'w');
        fputcsv($handle, ['code', 'created', 'client', 'assigned', 'status', 'quotes', 'invoices', 'subject']);
        foreach ($requests as $r) {
            fputcsv($handle, [$r['code'], date('d.m.Y', $r['created_ts']), $r['client_email'], $r['assigned'], $r['status']->value, count($r['quotes']), count($r['invoices']), $r['subject']]);
        }
        fclose($handle);
        $this->line('Список: '.$file);
    }
}
