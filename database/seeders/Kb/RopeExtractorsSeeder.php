<?php

namespace Database\Seeders\Kb;

use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\ParameterExtractor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Сидер экстракторов для категории traction_rope (тяговый канат).
 *
 * Покрывает российскую маркировку (ГОСТ 2688/3077/7668/7669/3083/3066):
 *  - диаметр (∅8мм, 10 мм, диам. 12,5 мм)
 *  - конструкция (6×19, 6×36, 8×19, 6х25)
 *  - сердечник (о.с./м.с./органический/металлический)
 *  - тип контакта (ТК, ЛК-Р, ЛК-О, ЛК-РО, ТЛК)
 *  - ГОСТ
 *  - длина (отрезок в метрах)
 *
 * Идемпотентен: updateOrCreate по (category_id, source_field, description).
 *
 * Запуск отдельно:
 *   php artisan db:seed --class="Database\\Seeders\\Kb\\RopeExtractorsSeeder"
 */
class RopeExtractorsSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/Kb/data/rope_extractor.json');
        if (!is_file($path)) {
            throw new RuntimeException("RopeExtractorsSeeder: data file not found at {$path}");
        }

        $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $category = EquipmentCategory::where('slug', 'traction_rope')->first();
        if (!$category) {
            Log::warning('RopeExtractorsSeeder: category traction_rope not found, пропускаем');
            return;
        }

        // Идемпотентность: один экстрактор на (category_id, source_field, brand_id=null,
        // без триггера по SKU). Если за этим стартовым экстрактором куратор завёл свой —
        // обновляем именно стартовый, а не куратора.
        ParameterExtractor::updateOrCreate(
            [
                'category_id' => $category->id,
                'source_field' => $payload['source_field'],
                'brand_id' => null,
                'triggered_by_sku_pattern_id' => null,
            ],
            [
                'rules' => $payload['rules'],
                'pre_normalize_rules' => $payload['pre_normalize_rules'] ?? [],
                'post_extract_rules' => $payload['post_extract_rules'] ?? (object) [],
                'test_examples' => $payload['test_examples'] ?? [],
                'priority' => $payload['priority'] ?? 100,
                'is_active' => $payload['is_active'] ?? true,
                'description' => $payload['description'],
            ]
        );
    }
}
