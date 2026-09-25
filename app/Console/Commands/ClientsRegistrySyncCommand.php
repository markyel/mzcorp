<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Clients\OrganizationRegistryService;
use Illuminate\Console\Command;

/**
 * Сверка реестра организаций с ЕГРЮЛ/ЕГРИП.
 *
 * Берёт организации с ИНН, которые ещё не сверялись или сверялись давно, и
 * записывает официальные реквизиты — по правилам OrganizationRegistryService:
 * мусорные названия меняет, хорошие не трогает.
 *
 *   php artisan clients:registry-sync                 # несверенные и старше 30 дней
 *   php artisan clients:registry-sync --limit=50      # порцией
 *   php artisan clients:registry-sync --stale-days=0  # все подряд
 *
 * Бесплатный лимит DaData — 10 000 запросов в сутки; весь реестр — ~1 000.
 */
class ClientsRegistrySyncCommand extends Command
{
    protected $signature = 'clients:registry-sync
        {--limit=0 : Сколько организаций за прогон (0 — все подходящие)}
        {--stale-days=30 : Пересверять, если последняя сверка старше N дней}';

    protected $description = 'Сверить реквизиты организаций с ЕГРЮЛ/ЕГРИП (DaData) по ИНН';

    public function handle(OrganizationRegistryService $registry): int
    {
        if (trim((string) config('services.dadata.api_key')) === '') {
            $this->error('Не задан DADATA_API_KEY — сверять нечем.');

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $staleDays = max(0, (int) $this->option('stale-days'));

        $query = Organization::query()
            ->whereNotNull('inn')->where('inn', '!=', '')
            ->where(fn ($q) => $q->whereNull('registry_checked_at')
                ->when($staleDays > 0, fn ($w) => $w->orWhere('registry_checked_at', '<', now()->subDays($staleDays)))
                ->when($staleDays === 0, fn ($w) => $w->orWhereNotNull('registry_checked_at')))
            ->orderByRaw('registry_checked_at NULLS FIRST')
            ->orderBy('id');

        $total = (clone $query)->count();
        $this->info('К сверке: '.$total.($limit > 0 ? ', за прогон: '.min($limit, $total) : '').'.');

        $stats = ['checked' => 0, 'unchanged' => 0, 'updated' => 0, 'renamed' => 0, 'kpp' => 0,
            'not_found' => 0, 'defunct' => 0, 'failed' => 0];
        $renamed = [];
        $defunct = [];

        foreach (($limit > 0 ? $query->limit($limit) : $query)->cursor() as $org) {
            $before = (string) $org->name;
            $res = $registry->sync($org);
            $stats['checked']++;

            if (! $res['ok']) {
                $stats['failed']++;

                continue;
            }

            $res['changed'] === [] ? $stats['unchanged']++ : $stats['updated']++;
            if (isset($res['changed']['name'])) {
                $stats['renamed']++;
                $renamed[] = [$before, (string) $org->name, (string) $org->inn];
            }
            if (isset($res['changed']['kpp'])) {
                $stats['kpp']++;
            }
            if ($res['status'] === 'NOT_FOUND') {
                $stats['not_found']++;
            }
            if ($org->isDefunct()) {
                $stats['defunct']++;
                $defunct[] = [(string) $org->name, (string) $org->inn, (string) $org->registryStatusLabel()];
            }

            // DaData не любит залпов — небольшая пауза бережёт от 429.
            usleep(120_000);
        }

        $this->table(['метрика', 'значение'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());

        if ($renamed !== []) {
            $this->newLine();
            $this->info('Названия заменены на официальные:');
            $this->table(['было', 'стало', 'ИНН'], $renamed);
        }
        if ($defunct !== []) {
            $this->newLine();
            $this->warn('Закрытые по реестру:');
            $this->table(['организация', 'ИНН', 'статус'], $defunct);
        }

        return self::SUCCESS;
    }
}
