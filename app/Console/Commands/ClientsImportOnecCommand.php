<?php

namespace App\Console\Commands;

use App\Services\Clients\ClientDiscountImportService;
use App\Services\Clients\OnecClientBaseImportService;
use Illuminate\Console\Command;

/**
 * Клиентская база из выгрузок 1С (OnecClientBaseImportService): организации из контрагентов-клиентов,
 * контакты клиентов с их организациями, организации заявок, затем скидки по ИНН
 * (ClientDiscountImportService — как загрузка на странице «Скидки»). Без --apply только отчёт.
 *
 *   php artisan clients:import-onec --counterparties=контрагенты.xlsx --contacts=контакты.xlsx \
 *       --discounts=скидки.xlsx --quotes=КП1.xlsx --quotes=КП2.xlsx [--apply]
 */
class ClientsImportOnecCommand extends Command
{
    protected $signature = 'clients:import-onec
        {--counterparties= : «Список контрагентов» 1С (xlsx, иерархический)}
        {--contacts= : «Список контактов» 1С (xlsx)}
        {--discounts= : Скидки клиентов (Контрагент | ИНН | ГруппаКомпаний | СкидкаПроцент)}
        {--quotes=* : Журнал КП 1С с номером заявки в комментарии — для организаций заявок}
        {--apply : Записать; без флага только отчёт}';

    protected $description = 'Клиенты, контакты и скидки из выгрузок 1С; организации заявок';

    public function handle(OnecClientBaseImportService $import, ClientDiscountImportService $discounts): int
    {
        ini_set('memory_limit', '3G');
        foreach (['counterparties', 'contacts'] as $option) {
            if (! is_file((string) $this->option($option))) {
                $this->error("Нет файла --{$option}");

                return self::FAILURE;
            }
        }

        $this->line(date('H:i:s ').'читаю контрагентов');
        $counterparties = $import->counterparties((string) $this->option('counterparties'));
        $this->line(date('H:i:s ').'читаю контакты');
        $contacts = $import->contacts((string) $this->option('contacts'));
        $quotes = $import->quoteClients(array_values(array_filter((array) $this->option('quotes'), 'is_file')));
        $plan = $import->plan($counterparties, $contacts, $quotes, config('retro.free_mail_domains', []));
        ksort($plan['stats']);
        foreach ($plan['stats'] as $key => $value) {
            $this->line(sprintf('  %-55s %d', $key, $value));
        }

        $parsed = null;
        if ($this->option('discounts')) {
            $parsed = $discounts->parse((string) $this->option('discounts'));
            // Замечания по строкам (нет ИНН, странная скидка) — не повод бросать файл.
            foreach ($parsed['errors'] as $error) {
                $this->warn('  скидки: '.$error);
            }
            if ($parsed['rows'] === []) {
                $this->error('В файле скидок нет ни одной годной строки.');

                return self::FAILURE;
            }
            $planned = array_flip(array_filter(array_map(fn ($o) => $o['inn'], $plan['organizations'])));
            $this->line(sprintf('  %-55s %d (у клиентов из списка контрагентов: %d)', 'скидок в файле', count($parsed['rows']),
                count(array_filter($parsed['rows'], fn ($r) => isset($planned[$r['inn']])))));
        }

        if (! $this->option('apply')) {
            $this->line('Без --apply ничего не записано.');

            return self::SUCCESS;
        }

        $this->line(date('H:i:s ').'записываю');
        foreach ($import->apply($plan) as $key => $value) {
            $this->line(sprintf('  %-55s %d', $key, $value));
        }
        if ($parsed !== null) {
            foreach ($discounts->apply($parsed['rows']) as $key => $value) {
                $this->line(sprintf('  скидки: %-46s %d', $key, $value));
            }
        }
        $this->info(date('H:i:s ').'готово');

        return self::SUCCESS;
    }
}
