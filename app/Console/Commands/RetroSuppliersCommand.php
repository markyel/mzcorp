<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Retro\RetroIndex;
use App\Services\Retro\RetroSupplierCollector;
use App\Services\Supplier\SupplierOrganizationService;
use App\Services\Supplier\SupplierRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Справочник поставщиков из индекса писем: адресаты наших маркированных запросов
 * расценки и авторы ответов на них (RetroSupplierCollector). Без GPT: описание
 * ассортимента и матрица не строятся. Пишет только в указанную базу и только с --apply;
 * уже заведённые адреса не переписывает, дополняет пустые поля. Адреса одного домена
 * (кроме бесплатной почты) объединяет в организацию.
 *
 *   php artisan retro:suppliers --database=MyLift_retro2
 *   php artisan retro:suppliers --database=MyLift_retro2 --apply
 */
class RetroSuppliersCommand extends Command
{
    protected $signature = 'retro:suppliers
        {--database= : Имя базы (обязательно)}
        {--apply : Записать; без флага только отчёт}';

    protected $description = 'Заполняет справочник поставщиков по маркированным запросам расценки из индекса писем';

    public function handle(SupplierRegistry $registry, SupplierOrganizationService $organizations): int
    {
        $database = (string) $this->option('database');
        if ($database === '') {
            $this->error('Укажите базу: --database=MyLift_retro2');

            return self::FAILURE;
        }
        $connection = (string) config('database.default');
        config(["database.connections.$connection.database" => $database]);
        DB::purge($connection);

        $index = new RetroIndex((string) config('retro.index_path'), (int) config('retro.year'), (int) config('retro.max_legacy_number'));
        $suppliers = (new RetroSupplierCollector(config('retro')))->collect($index->load());
        $existing = Supplier::query()->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->flip();

        $byDomain = [];
        foreach ($suppliers as $s) {
            if (! $s['free_mail']) {
                $byDomain[$s['domain']][] = $s['email'];
            }
        }
        $orgDomains = array_filter($byDomain, fn ($list) => count($list) > 1);
        $languages = array_count_values(array_column($suppliers, 'language'));
        $this->info(sprintf('Поставщиков: %d (уже в справочнике %d), доменов %d, из них с несколькими адресами %d; язык: %s',
            count($suppliers), count(array_filter($suppliers, fn ($s) => isset($existing[$s['email']]))),
            count($byDomain), count($orgDomains), json_encode($languages)));
        $this->writeList($suppliers);

        if (! $this->option('apply')) {
            $this->line('Без --apply ничего не записано.');

            return self::SUCCESS;
        }

        $created = 0;
        $filled = 0;
        $ids = [];
        foreach ($suppliers as $s) {
            $isNew = ! isset($existing[$s['email']]);
            $supplier = $registry->registerEmail($s['email']);
            if ($supplier === null) {
                continue;
            }
            $ids[$s['email']] = $supplier->id;
            $fields = array_filter([
                'domain' => $supplier->domain ?: ($s['free_mail'] ? null : $s['domain']),
                'contact_person' => $supplier->contact_person ?: $s['contact'],
                'language' => $supplier->language ?: $s['language'],
                'notes' => $supplier->notes ?: sprintf('Восстановлен из почты после потери базы: наших запросов %d, ответов %d, %s–%s.',
                    $s['requests'], $s['replies'], date('d.m.Y', $s['first_ts']), date('d.m.Y', $s['last_ts'])),
            ], fn ($v) => $v !== null && $v !== '');
            $changed = array_diff_assoc($fields, array_intersect_key($supplier->getAttributes(), $fields));
            if ($changed !== []) {
                $supplier->forceFill($changed)->save();
                $filled += $isNew ? 0 : 1;
            }
            $created += $isNew ? 1 : 0;
        }
        $merged = 0;
        foreach ($orgDomains as $domain => $emails) {
            $memberIds = array_values(array_filter(array_map(fn ($e) => $ids[$e] ?? null, $emails)));
            if (count($memberIds) > 1 && $organizations->merge($memberIds) !== null) {
                $merged++;
            }
        }
        $this->info("Заведено новых: $created, дополнено существующих: $filled, организаций: $merged");

        return self::SUCCESS;
    }

    /** Список в журналах ретрозагрузки, не в репозиторий: там адреса поставщиков. */
    private function writeList(array $suppliers): void
    {
        $dir = storage_path('logs/retro');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir.'/suppliers-'.date('Ymd-His').'.csv';
        $handle = fopen($file, 'w');
        fputcsv($handle, ['email', 'domain', 'contact', 'language', 'requests', 'replies', 'first', 'last']);
        foreach ($suppliers as $s) {
            fputcsv($handle, [$s['email'], $s['domain'], $s['contact'], $s['language'], $s['requests'], $s['replies'], date('d.m.Y', $s['first_ts']), date('d.m.Y', $s['last_ts'])]);
        }
        fclose($handle);
        $this->line('Список: '.$file);
    }
}
