<?php

namespace Database\Seeders\Kb;

use App\Models\Kb\EquipmentCategory;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * KB §7.4: 15 детальных категорий идентификации.
 *
 * Источник данных: database/seeders/Kb/data/equipment_categories.json.
 */
class EquipmentCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/Kb/data/equipment_categories.json');
        if (!is_file($path)) {
            throw new RuntimeException("EquipmentCategoriesSeeder: data file not found at {$path}");
        }

        $rows = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        foreach ($rows as $row) {
            EquipmentCategory::updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'compatible_equipment' => $row['compatible_equipment'] ?? ['lift'],
                    'is_industry_specific' => $row['is_industry_specific'] ?? false,
                    'synonyms' => $row['synonyms'] ?? [],
                    'description' => $row['description'] ?? null,
                    'is_active' => true,
                ]
            );
        }
    }
}
