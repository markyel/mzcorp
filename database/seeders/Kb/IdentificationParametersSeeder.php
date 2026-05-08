<?php

namespace Database\Seeders\Kb;

use App\Models\Kb\IdentificationParameter;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * KB §7.6: атомарные параметры идентификации.
 *
 * Источник данных: database/seeders/Kb/data/identification_parameters.json.
 */
class IdentificationParametersSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/Kb/data/identification_parameters.json');
        if (!is_file($path)) {
            throw new RuntimeException("IdentificationParametersSeeder: data file not found at {$path}");
        }

        $rows = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        foreach ($rows as $row) {
            IdentificationParameter::updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'value_type' => $row['value_type'],
                    'allowed_values' => $row['allowed_values'] ?? [],
                    'aliases' => $row['aliases'] ?? [],
                    'unit' => $row['unit'] ?? null,
                    'question_template' => $row['question_template'],
                    'description' => $row['description'] ?? null,
                    'is_active' => true,
                ]
            );
        }
    }
}
