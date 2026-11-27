<?php

namespace App\Console\Commands;

use App\Models\Identification\IdentificationHint;
use App\Models\Identification\IdentificationPartType;
use App\Services\Catalog\IdentificationHintService;
use Illuminate\Console\Command;

/**
 * Начальное наполнение справочника опознания из config/identification_hints.php. Повторный запуск ничего не
 * портит: есть тип — не трогаем (его могли поправить на странице справочника), новые вопросы — добавляем.
 *
 *   php artisan identification:seed
 */
class IdentificationSeedCommand extends Command
{
    protected $signature = 'identification:seed';

    protected $description = 'Справочник опознания деталей: начальное наполнение из config';

    public function handle(): int
    {
        $types = 0;
        $hints = 0;
        foreach (IdentificationHintService::seedTypes() as $i => $t) {
            $type = IdentificationPartType::query()->firstOrCreate(['key' => $t['key']], [
                'name' => $t['name'], 'pattern' => $t['pattern'], 'sort' => ($i + 1) * 10, 'is_active' => true, 'source' => 'seed',
            ]);
            $types += $type->wasRecentlyCreated ? 1 : 0;
            foreach (array_values($t['hints']) as $j => $h) {
                $hint = IdentificationHint::query()->firstOrCreate(['part_type_id' => $type->id, 'label' => $h['label']], [
                    'question' => $h['question'], 'status' => IdentificationHint::ACTIVE, 'source' => 'seed', 'sort' => ($j + 1) * 10,
                ]);
                $hints += $hint->wasRecentlyCreated ? 1 : 0;
            }
        }
        IdentificationHintService::forget();
        $this->info(sprintf('Добавлено типов: %d, вопросов: %d.', $types, $hints));

        return self::SUCCESS;
    }
}
