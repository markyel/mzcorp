<?php

namespace App\Console\Commands;

use App\Enums\MailboxType;
use App\Enums\Role;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Retro\RetroAssembler;
use App\Services\Retro\RetroIndex;
use App\Services\Retro\RetroWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Заявки за дни аварии (RetroAssembler::assembleCrash): CRM не работала, обращения с info@
 * пересылали менеджерам, часть клиентов писала менеджеру лично. Номер — «MZ2026-00001…»
 * (решение заказчика 05.11), в parsing_meta — crash и crash_source (forwarded|direct).
 * Дописывает в собранную базу (RetroWriter), цепочки, письма которых уже в базе, пропускает.
 * После записи прогнать retro:hydrate, retro:attachments, retro:totals, retro:items.
 * Без --apply только отчёт и списки в журналах ретрозагрузки.
 *
 *   php artisan retro:crash --database=MyLift_retro2
 *   php artisan retro:crash --database=MyLift_retro2 --apply
 */
class RetroCrashCommand extends Command
{
    protected $signature = 'retro:crash
        {--database= : Имя базы (обязательно)}
        {--include= : Файл со строками «ящик|папка|uid»: цепочки без менеджера, которые всё же заявки (заводятся без ответственного)}
        {--apply : Записать; без флага только отчёт}';

    protected $description = 'Заявки MZ… за дни аварии: обращения на info@ с пересылкой менеджеру и письма менеджерам лично';

    public function handle(RetroWriter $writer): int
    {
        $database = (string) $this->option('database');
        if ($database === '') {
            $this->error('Укажите базу: --database=MyLift_retro2');

            return self::FAILURE;
        }
        $connection = (string) config('database.default');
        config(["database.connections.$connection.database" => $database]);
        DB::purge($connection);

        $this->line(date('H:i:s ').'читаю индекс и собираю');
        $index = new RetroIndex((string) config('retro.index_path'), (int) config('retro.year'), (int) config('retro.max_legacy_number'));
        $handlers = User::query()->role(Role::requestHandlerRoles())->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();
        $shared = Mailbox::query()->where('type', MailboxType::Shared)->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();
        $assembler = new RetroAssembler(config('retro'), $handlers, time());
        $result = $assembler->assemble($index->load());
        $rows = $result['rows'];

        // Письма, которые уже в базе: такую цепочку не трогаем (повторный запуск безопасен).
        $known = DB::table('email_messages as e')->join('mailboxes as m', 'm.id', '=', 'e.mailbox_id')
            ->whereNotNull('e.imap_uid')->selectRaw("lower(m.email) || '|' || e.folder || '|' || e.imap_uid as k")
            ->pluck('k')->flip()->all();
        $year = (int) config('retro.year');
        $last = (int) DB::table('requests')->where('internal_code', 'like', "MZ$year-%")
            ->selectRaw("max(cast(split_part(internal_code, '-', 2) as integer)) as n")->value('n');

        $include = [];
        if ($this->option('include')) {
            foreach (file((string) $this->option('include'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $include[mb_strtolower(trim($line))] = true;
            }
        }
        $key = fn (array $row) => mb_strtolower($row['box']).'|'.mb_strtolower($row['folder']).'|'.$row['uid'];

        // assemble() уже связал R-цепочки до аварии; заявки R здесь не собираем — только дни аварии.
        $crash = $assembler->assembleCrash($rows, $shared, $last + 1, function (array $chain) use ($known) {
            foreach ($chain as $row) {
                if (isset($known[mb_strtolower($row['box']).'|'.$row['folder'].'|'.$row['uid']])) {
                    return false;
                }
            }

            return true;
        }, $include === [] ? null : function (array $chain) use ($include, $key) {
            foreach ($chain as $row) {
                if (isset($include[$key($row)])) {
                    return true;
                }
            }

            return false;
        });
        $requests = $crash['requests'];

        $this->report($requests, $crash['skipped'], $crash['stats'], $rows);
        if (! $this->option('apply') || $requests === []) {
            $this->line($requests === [] ? 'Новых заявок нет.' : 'Без --apply ничего не записано.');

            return self::SUCCESS;
        }

        $unseen = [];
        foreach ($index->mailboxes() as $box) {
            $unseen[$box] = $index->unseen($box);
        }
        $this->line(date('H:i:s ').'записываю в базу '.$database);
        $counts = $writer->write(['requests' => $requests, 'rows' => $rows], $unseen, (int) config('services.invoices.default_validity_business_days', 5));
        foreach ($requests as $r) {
            DB::table('requests')->where('internal_code', $r['code'])->update(['parsing_meta' => DB::raw(
                "coalesce(parsing_meta::jsonb, '{}'::jsonb) || ".DB::getPdo()->quote(json_encode(['crash' => true, 'crash_source' => $r['crash_source']])).'::jsonb'
            )]);
        }
        foreach ($counts as $key => $value) {
            $this->line("  $key: $value");
        }
        $this->info(date('H:i:s ').'готово. Дальше: retro:hydrate, retro:attachments, retro:totals, retro:items');

        return self::SUCCESS;
    }

    private function report(array $requests, array $skipped, array $stats, array $rows): void
    {
        $status = [];
        $assigned = [];
        $source = [];
        foreach ($requests as $r) {
            $status[$r['status']->value] = ($status[$r['status']->value] ?? 0) + 1;
            $assigned[$r['assigned'] ?? 'без ответственного'] = ($assigned[$r['assigned'] ?? 'без ответственного'] ?? 0) + 1;
            $source[$r['crash_source']] = ($source[$r['crash_source']] ?? 0) + 1;
        }
        arsort($status);
        arsort($assigned);
        $codes = array_column($requests, 'code');
        $this->info(sprintf('Заявок за дни аварии: %d (%s … %s), КП: %d, счетов: %d, писем: %d',
            count($requests), $codes[0] ?? '—', end($codes) ?: '—',
            array_sum(array_map(fn ($r) => count($r['quotes']), $requests)),
            array_sum(array_map(fn ($r) => count($r['invoices']), $requests)),
            array_sum(array_map(fn ($r) => count($r['messages']), $requests))));
        $this->line('Источник: '.json_encode($source, JSON_UNESCAPED_UNICODE).' (forwarded — через общий ящик, direct — лично менеджеру)');
        $this->line('Статусы: '.json_encode($status, JSON_UNESCAPED_UNICODE));
        $this->line('Ответственные: '.json_encode($assigned, JSON_UNESCAPED_UNICODE));
        $this->line('Сборка: '.json_encode($stats, JSON_UNESCAPED_UNICODE));

        $dir = storage_path('logs/retro');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $stamp = date('Ymd-His');
        $file = "$dir/crash-$stamp.csv";
        $handle = fopen($file, 'w');
        fputcsv($handle, ['code', 'created', 'source', 'client', 'assigned', 'status', 'letters', 'quotes', 'invoices', 'subject']);
        foreach ($requests as $r) {
            fputcsv($handle, [$r['code'], date('d.m.Y H:i', $r['created_ts']), $r['crash_source'], $r['client_email'], $r['assigned'], $r['status']->value,
                count($r['messages']), implode(' ', array_column($r['quotes'], 'number')), implode(' ', array_column($r['invoices'], 'number')), $r['subject']]);
        }
        fclose($handle);
        $skip = "$dir/crash-skipped-$stamp.csv";
        $handle = fopen($skip, 'w');
        fputcsv($handle, ['reason', 'date', 'box', 'folder', 'uid', 'from', 'client', 'letters', 'subject']);
        foreach ($skipped as $s) {
            fputcsv($handle, [$s['reason'], date('d.m.Y H:i', $s['first']['ts'] ?? 0), $s['first']['box'], $s['first']['folder'], $s['first']['uid'], $s['first']['from_email'], $s['client'], $s['count'], $s['first']['subject']]);
        }
        fclose($handle);
        $this->line("Список: $file\nПропущенные: $skip");
    }
}
