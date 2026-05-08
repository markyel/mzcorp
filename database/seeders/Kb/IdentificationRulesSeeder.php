<?php

namespace Database\Seeders\Kb;

use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\IdentificationParameter;
use App\Models\Kb\IdentificationRule;
use App\Models\Kb\IdentificationRuleAlternative;
use App\Models\Kb\ManufacturerBrand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * KB §7.7: правила идентификации с альтернативами.
 *
 * Источник: database/seeders/Kb/data/identification_rules.json.
 *
 * В JSON разрешены два способа задать список брендов:
 *   - applies_to_brands: [<int_id>, ...] — прямой список ID
 *   - applies_to_brands_names: ["OTIS", ...] — имена, резолвятся по ManufacturerBrand.name
 *
 * Идемпотентен: обновляет правила и альтернативы по сочетанию (category_id + description).
 */
class IdentificationRulesSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/Kb/data/identification_rules.json');
        if (!is_file($path)) {
            throw new RuntimeException("IdentificationRulesSeeder: data file not found at {$path}");
        }

        $rows = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        // Сборка списка ожидаемых description (по category_slug) — для удаления stale-правил
        $expectedByCategory = [];
        foreach ($rows as $row) {
            $expectedByCategory[$row['category_slug']][] = $row['description'];
        }

        // Удаляем правила, описание которых отсутствует в JSON (stale после переименования
        // description в JSON). Сохраняет только то что в файле + то что куратор добавил
        // вручную через UI с описанием НЕ из JSON-сидера.
        // Чтобы отличить «созданное курацией» от «было в сидере, но удалили» —
        // сидерные описания всегда начинаются с «<Имя категории> — общее правило»
        // или маркоспецифичных префиксов. Это эвристика, но рабочая.
        foreach ($expectedByCategory as $slug => $expected) {
            $cat = EquipmentCategory::where('slug', $slug)->first();
            if (!$cat) continue;

            $stale = IdentificationRule::query()
                ->where('category_id', $cat->id)
                ->whereNotIn('description', $expected)
                ->where('description', 'LIKE', '%— общее правило%') // маркер сидерных правил
                ->get();
            foreach ($stale as $r) {
                $r->alternatives()->delete();
                $r->delete();
            }
        }

        foreach ($rows as $row) {
            $category = EquipmentCategory::where('slug', $row['category_slug'])->first();
            if (!$category) {
                Log::warning("IdentificationRulesSeeder: category not found", ['slug' => $row['category_slug']]);
                continue;
            }

            // Резолв applies_to_brands
            $brandIds = null;
            if (isset($row['applies_to_brands_names']) && is_array($row['applies_to_brands_names'])) {
                $brandIds = ManufacturerBrand::whereIn('name', $row['applies_to_brands_names'])
                    ->pluck('id')->all();
                if (count($brandIds) !== count($row['applies_to_brands_names'])) {
                    Log::warning("IdentificationRulesSeeder: not all brand names resolved", [
                        'expected' => $row['applies_to_brands_names'],
                        'resolved_ids' => $brandIds,
                    ]);
                }
            } elseif (array_key_exists('applies_to_brands', $row)) {
                $brandIds = $row['applies_to_brands']; // null или массив ID
            }

            $rule = IdentificationRule::updateOrCreate(
                [
                    'category_id' => $category->id,
                    'description' => $row['description'],
                ],
                [
                    'applies_to_brands' => $brandIds,
                    'priority' => $row['priority'] ?? 100,
                    'is_active' => true,
                ]
            );

            // Альтернативы — пересоздаём (источник истины JSON, в БД могли остаться от прошлой версии)
            $rule->alternatives()->delete();

            foreach ($row['alternatives'] as $alt) {
                $paramIds = IdentificationParameter::whereIn('slug', $alt['required_parameters'])
                    ->pluck('id')->all();

                if (count($paramIds) !== count($alt['required_parameters'])) {
                    Log::warning("IdentificationRulesSeeder: not all parameters resolved", [
                        'rule_description' => $row['description'],
                        'expected_slugs' => $alt['required_parameters'],
                        'resolved_ids' => $paramIds,
                    ]);
                }

                IdentificationRuleAlternative::create([
                    'rule_id' => $rule->id,
                    'required_parameter_ids' => $paramIds,
                    'label' => $alt['label'] ?? null,
                    'preference_order' => $alt['preference_order'] ?? 100,
                ]);
            }
        }
    }
}
