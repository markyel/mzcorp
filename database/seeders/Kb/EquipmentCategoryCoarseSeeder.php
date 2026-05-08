<?php

namespace Database\Seeders\Kb;

use App\Constants\CoarseCategories;
use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\EquipmentCategoryCoarse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * KB §7.5: many-to-many связи детальная категория ↔ грубая.
 *
 * Используются реальные имена грубых категорий из NormalizeSupplierProfileJob::CATEGORIES
 * (через CoarseCategories::ALL).
 */
class EquipmentCategoryCoarseSeeder extends Seeder
{
    public function run(): void
    {
        $mapping = [
            'microswitch'                => [['Электроника и платы', true], ['Безопасность', false], ['Электрика', false]],
            'contactor'                  => [['Электрика', true], ['Автоматика и реле', false]],
            'frequency_converter'        => [['Частотные преобразователи', true], ['Электроника и платы', false]],
            'guide_shoe_insert'          => [['Механика кабины', true]],
            'door_skate'                 => [['Механика дверей', true], ['Электрика', false]],
            'elevator_button'            => [['Кнопки и индикация', true]],
            'door_drive'                 => [['Привод дверей', true], ['Механика дверей', false]],
            'controller'                 => [['Электроника и платы', true]],
            'control_board'              => [['Электроника и платы', true]],
            'traction_rope'              => [['Канаты и ремни', true]],
            'roller'                     => [['Подшипники и ролики', true], ['Механика дверей', false]],
            'load_sensor'                => [['Датчики и энкодеры', true], ['Электроника и платы', false]],
            'buffer'                     => [['Безопасность', true]],
            'escalator_traction_chain'   => [['Эскалаторы', true]],
            'escalator_step'             => [['Эскалаторы', true]],
            'emergency_lighting'         => [['Освещение', true], ['Безопасность', false], ['Батареи и ИБП', false]],
            'cabin_lighting'             => [['Освещение', true]],
            'speed_governor_belt'        => [['Канаты и ремни', true], ['Безопасность', false]],
            // Новые категории (16 шт.)
            'escalator_gearbox'          => [['Эскалаторы', true]],
            'escalator_balustrade_chain' => [['Эскалаторы', true]],
            'escalator_sprocket'         => [['Эскалаторы', true]],
            'escalator_brake'            => [['Эскалаторы', true], ['Безопасность', false]],
            'escalator_comb_plate'       => [['Эскалаторы', true]],
            'limit_switch'               => [['Безопасность', true], ['Электрика', false], ['Эскалаторы', false]],
            'speed_governor'             => [['Безопасность', true]],
            'light_curtain'              => [['Безопасность', true], ['Механика дверей', false]],
            'intercom_unit'              => [['Электроника и платы', true], ['Безопасность', false]],
            'power_supply'               => [['Электрика', true], ['Электроника и платы', false]],
            'traction_motor'             => [['Электрика', true], ['Механика кабины', false]],
            'traction_belt'              => [['Канаты и ремни', true]],
            'door_sill'                  => [['Пороги', true], ['Механика дверей', false]],
            'encoder'                    => [['Датчики и энкодеры', true], ['Электроника и платы', false]],
            'traction_sheave'            => [['Механика кабины', true], ['Канаты и ремни', false]],
            'escalator_handrail'         => [['Эскалаторы', true]],
            'brake_coil'                 => [['Электрика', true], ['Безопасность', false]],
            'traction_brake'             => [['Безопасность', true], ['Механика кабины', false], ['Механика дверей', false]],
        ];

        foreach ($mapping as $slug => $coarses) {
            $category = EquipmentCategory::where('slug', $slug)->first();
            if (!$category) {
                Log::warning("EquipmentCategoryCoarseSeeder: category not found", ['slug' => $slug]);
                continue;
            }

            foreach ($coarses as [$coarseName, $isPrimary]) {
                if (!CoarseCategories::isValid($coarseName)) {
                    throw new RuntimeException(
                        "EquipmentCategoryCoarseSeeder: '{$coarseName}' не входит в CoarseCategories::ALL "
                        . "(сверьтесь с NormalizeSupplierProfileJob::CATEGORIES). slug={$slug}"
                    );
                }

                EquipmentCategoryCoarse::updateOrCreate(
                    [
                        'category_id' => $category->id,
                        'coarse_category' => $coarseName,
                    ],
                    ['is_primary' => $isPrimary]
                );
            }
        }
    }
}
