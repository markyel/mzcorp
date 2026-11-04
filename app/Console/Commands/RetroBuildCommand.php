<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\Retro\RetroAssembler;
use App\Services\Retro\RetroIndex;
use App\Services\Retro\RetroWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Собирает заявки, КП и счета из индекса писем (deploy/recovery/RETRO_PLAN.md).
 * Пишет только в указанную базу и только в пустую: пересборка — это новая
 * копия чистой базы. Не отправляет писем и не ставит задач в очередь.
 *
 *   php artisan retro:build --database=MyLift_retro --dry-run
 *   php artisan retro:build --database=MyLift_retro
 */
class RetroBuildCommand extends Command
{
    protected $signature = 'retro:build
        {--database= : Имя базы, в которую собирать (обязательно)}
        {--dry-run : Только посчитать, ничего не записывать}';

    protected $description = 'Собирает заявки, КП и счета из индекса писем, без GPT и без конвейера';

    public function handle(RetroWriter $writer): int
    {
        ini_set('memory_limit', '-1');
        $database = (string) $this->option('database');
        if ($database === '') {
            $this->error('Укажите базу: --database=MyLift_retro');

            return self::FAILURE;
        }
        $connection = (string) config('database.default');
        config(["database.connections.$connection.database" => $database]);
        DB::purge($connection);

        $requests = DB::table('requests')->count();
        $jobs = DB::table('jobs')->count();
        if (! $this->option('dry-run') && ($requests > 0 || $jobs > 0)) {
            $this->error("База $database не пуста: заявок $requests, задач в очереди $jobs. Пересборка идёт в новую копию чистой базы.");

            return self::FAILURE;
        }

        $this->line(date('H:i:s ').'читаю индекс');
        $index = new RetroIndex((string) config('retro.index_path'), (int) config('retro.year'), (int) config('retro.max_legacy_number'));
        $rows = $index->load();

        $handlers = User::query()->role(Role::requestHandlerRoles())->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();
        $this->line(date('H:i:s ').'собираю заявки, писем '.count($rows));
        $assembler = new RetroAssembler(config('retro'), $handlers, time());
        $result = $assembler->assemble($rows);
        unset($rows);

        $this->report($result);
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $unseen = [];
        foreach ($index->mailboxes() as $box) {
            $unseen[$box] = $index->unseen($box);
        }
        $this->line(date('H:i:s ').'записываю в базу '.$database);
        $validity = (int) config('services.invoices.default_validity_business_days', 5);
        $counts = $writer->write($result, $unseen, $validity);
        foreach ($counts as $key => $value) {
            $this->line("  $key: $value");
        }
        $this->info(date('H:i:s ').'готово; задач в очереди: '.DB::table('jobs')->count());

        return self::SUCCESS;
    }

    private function report(array $result): void
    {
        $requests = $result['requests'];
        $status = [];
        $assigned = [];
        $messages = 0;
        $supplier = 0;
        $withClient = 0;
        $quotes = 0;
        $invoices = 0;
        foreach ($requests as $r) {
            $status[$r['status']->value] = ($status[$r['status']->value] ?? 0) + 1;
            $assigned[$r['assigned'] ?? 'без ответственного'] = ($assigned[$r['assigned'] ?? 'без ответственного'] ?? 0) + 1;
            $withClient += $r['client_email'] !== null ? 1 : 0;
            $quotes += count($r['quotes']);
            $invoices += count($r['invoices']);
            foreach ($r['messages'] as $m) {
                $messages++;
                $supplier += $m['supplier'] ? 1 : 0;
            }
        }
        arsort($status);
        arsort($assigned);
        $this->info('Заявок: '.count($requests).', с клиентом: '.$withClient);
        $this->line('Статусы: '.json_encode($status, JSON_UNESCAPED_UNICODE));
        $this->line('Ответственные: '.json_encode($assigned, JSON_UNESCAPED_UNICODE));
        $this->line("Писем в заявках: $messages, из них с поставщиками: $supplier");
        $this->line("КП: $quotes, счетов: $invoices");
        $this->line('Прочее: '.json_encode($result['stats'], JSON_UNESCAPED_UNICODE));
    }
}
