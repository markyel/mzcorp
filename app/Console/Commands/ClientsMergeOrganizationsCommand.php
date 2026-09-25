<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Clients\OrganizationMergeService;
use Illuminate\Console\Command;

/**
 * Слить карточки-двойники в основные организации.
 *
 *   php artisan clients:merge-organizations 465:158 472:984           # dry-run
 *   php artisan clients:merge-organizations 465:158 472:984 --apply
 *
 * Пара «откуда:куда»: первая карточка удаляется, всё с неё — заявки,
 * контакты, скидки, закрепления — переезжает во вторую.
 */
class ClientsMergeOrganizationsCommand extends Command
{
    protected $signature = 'clients:merge-organizations
        {pairs* : Пары from:into (id организаций)}
        {--apply : Реально слить (без флага — только показать)}';

    protected $description = 'Слить организации-двойники в основные карточки';

    public function handle(OrganizationMergeService $merger): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];

        foreach ((array) $this->argument('pairs') as $pair) {
            if (! preg_match('/^(\d+):(\d+)$/', trim((string) $pair), $m)) {
                $this->error("Пара «{$pair}» — нужен формат from:into.");

                return self::FAILURE;
            }
            $from = Organization::find((int) $m[1]);
            $into = Organization::find((int) $m[2]);
            if (! $from || ! $into || $from->id === $into->id) {
                $rows[] = [$pair, $from?->name ?? '—', $into?->name ?? '—', 'пропущено: нет карточки или одна и та же'];

                continue;
            }

            if (! $apply) {
                $rows[] = [$pair, $from->name, $into->name.' ['.($into->inn ?: 'без ИНН').']',
                    'заявок '.$from->requests()->count().', контактов '.$from->contacts()->count()];

                continue;
            }

            $s = $merger->merge($from, $into);
            $rows[] = [$pair, $from->name, $into->name.' ['.($into->inn ?: 'без ИНН').']',
                sprintf('заявок %d, контактов +%d, скидок %d%s', $s['requests'], $s['contacts'], $s['discounts'],
                    $s['taken'] !== [] ? ', взято: '.implode(', ', $s['taken']) : '')];
        }

        $this->table(['пара', 'двойник', 'основная', $apply ? 'перенесено' : 'будет перенесено'], $rows);
        if (! $apply) {
            $this->warn('DRY-RUN — запусти с --apply, чтобы слить.');
        }

        return self::SUCCESS;
    }
}
