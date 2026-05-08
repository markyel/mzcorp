<?php

namespace Database\Seeders\Kb;

use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\ParameterExtractor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Сидер экстракторов для категории door_skate (отводка дверей).
 *
 * Покрывает Fermator (CDL-P000XX000) и упоминания брендов
 * (Wittur, Selcom, Sematic, Fermator) в названии позиции.
 *
 * Идемпотентность: updateOrCreate по (category_id, source_field, brand_id=null,
 * triggered_by_sku_pattern_id=null).
 */
class DoorSkateExtractorsSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/Kb/data/door_skate_extractor.json');
        if (!is_file($path)) {
            throw new RuntimeException("DoorSkateExtractorsSeeder: data file not found at {$path}");
        }

        $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $category = EquipmentCategory::where('slug', 'door_skate')->first();
        if (!$category) {
            Log::warning('DoorSkateExtractorsSeeder: category door_skate not found, пропускаем');
            return;
        }

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
