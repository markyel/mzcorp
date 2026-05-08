<?php

namespace Database\Seeders\Kb;

use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\ParameterExtractor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Сидер экстракторов для категории contactor (контакторы).
 *
 * Покрывает Schneider TeSys (LC1D/LC1F) с пробелами и кириллицей,
 * катушку, ток, конфигурацию контактов.
 */
class ContactorExtractorsSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/Kb/data/contactor_extractor.json');
        if (!is_file($path)) {
            throw new RuntimeException("ContactorExtractorsSeeder: data file not found at {$path}");
        }

        $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $category = EquipmentCategory::where('slug', 'contactor')->first();
        if (!$category) {
            Log::warning('ContactorExtractorsSeeder: category contactor not found, пропускаем');
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
