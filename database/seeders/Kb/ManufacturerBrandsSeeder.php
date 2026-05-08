<?php

namespace Database\Seeders\Kb;

use App\Models\Kb\ManufacturerBrand;
use Illuminate\Database\Seeder;

/**
 * KB §7.2: стартовый набор производителей оборудования.
 *
 * Идемпотентен: использует updateOrCreate по name.
 */
class ManufacturerBrandsSeeder extends Seeder
{
    public function run(): void
    {
        $brands = [
            ['name' => 'OTIS', 'aliases' => ['Отис', 'OTIS Elevator', 'Otis', 'XIZI OTIS', 'Xizi Otis', 'XIZI', 'Сидзи Отис', 'Сити Лифт'], 'specialization_tags' => ['guide_shoe_insert', 'elevator_button', 'door_drive', 'controller', 'control_board', 'escalator_traction_chain', 'escalator_step', 'escalator_handrail_roller', 'roller']],
            ['name' => 'Wittur', 'aliases' => [], 'specialization_tags' => ['door_skate', 'door_drive', 'guide_shoe_insert', 'roller']],
            ['name' => 'Fermator', 'aliases' => ['Ферматор'], 'specialization_tags' => ['door_skate', 'door_drive']],
            ['name' => 'Selcom', 'aliases' => [], 'specialization_tags' => ['door_skate', 'door_drive']],
            ['name' => 'Sematic', 'aliases' => [], 'specialization_tags' => ['door_skate', 'door_drive', 'elevator_button']],
            ['name' => 'KONE', 'aliases' => [], 'specialization_tags' => ['controller', 'control_board', 'door_drive', 'guide_shoe_insert', 'escalator_traction_chain', 'escalator_step', 'roller']],
            ['name' => 'Schindler', 'aliases' => [], 'specialization_tags' => ['controller', 'control_board', 'frequency_converter', 'elevator_button', 'escalator_traction_chain', 'roller']],
            ['name' => 'ThyssenKrupp', 'aliases' => ['TK Elevator', 'TKE'], 'specialization_tags' => ['guide_shoe_insert', 'controller', 'roller']],
            ['name' => 'Vacon', 'aliases' => [], 'specialization_tags' => ['frequency_converter']],
            ['name' => 'FUJI', 'aliases' => ['Fuji Electric'], 'specialization_tags' => ['frequency_converter']],
            ['name' => 'Yaskawa', 'aliases' => [], 'specialization_tags' => ['frequency_converter']],
            ['name' => 'Schneider', 'aliases' => ['Schneider Electric', 'EasyPact', 'EasyPact TVS', 'TeSys', 'TeSys D'], 'specialization_tags' => ['contactor', 'microswitch']],
            ['name' => 'ABB', 'aliases' => [], 'specialization_tags' => ['contactor']],
            ['name' => 'Siemens', 'aliases' => ['Сименс'], 'specialization_tags' => ['contactor', 'controller']],
            ['name' => 'Crouzet', 'aliases' => [], 'specialization_tags' => ['microswitch']],
            ['name' => 'Omron', 'aliases' => [], 'specialization_tags' => ['microswitch']],
            ['name' => 'Honeywell', 'aliases' => [], 'specialization_tags' => ['microswitch']],
            ['name' => 'SJEC', 'aliases' => [], 'specialization_tags' => ['escalator_traction_chain', 'escalator_step']],
            ['name' => 'Mitsubishi', 'aliases' => [], 'specialization_tags' => ['escalator_traction_chain', 'escalator_step']],
            ['name' => 'STS', 'aliases' => [], 'specialization_tags' => ['load_sensor']],
            ['name' => 'Henning', 'aliases' => [], 'specialization_tags' => ['load_sensor', 'buffer']],
            ['name' => 'OLEO', 'aliases' => [], 'specialization_tags' => ['buffer']],
            ['name' => 'Drako', 'aliases' => [], 'specialization_tags' => ['traction_rope']],
            ['name' => 'Brugg', 'aliases' => [], 'specialization_tags' => ['traction_rope']],
            ['name' => 'Pfeifer', 'aliases' => [], 'specialization_tags' => ['traction_rope']],
            ['name' => 'Casar', 'aliases' => [], 'specialization_tags' => ['traction_rope']],
            ['name' => 'Gustav Wolf', 'aliases' => ['GW', 'Gustav-Wolf', 'GustavWolf'], 'specialization_tags' => ['traction_rope']],
            ['name' => 'PAWO', 'aliases' => ['Pawo Steel'], 'specialization_tags' => ['traction_rope']],
            ['name' => 'Bridon', 'aliases' => ['Bridon-Bekaert'], 'specialization_tags' => ['traction_rope']],
            ['name' => 'Verope', 'aliases' => [], 'specialization_tags' => ['traction_rope']],
            ['name' => 'Gates', 'aliases' => ['Gates Mectrol', 'PowerGrip'], 'specialization_tags' => ['speed_governor_belt']],
            ['name' => 'Continental', 'aliases' => ['ContiTech', 'Continental ContiTech', 'Synchrobelt', 'Synchroflex'], 'specialization_tags' => ['speed_governor_belt']],
            ['name' => 'Optibelt', 'aliases' => [], 'specialization_tags' => ['speed_governor_belt']],

            // Российские/СНГ OEM лифтостроения — сами производят запчасти (двери, замки, башмаки)
            ['name' => 'ЩЛЗ', 'aliases' => ['Щербинский лифтостроительный завод', 'Shcherbinka', 'Щербинский завод'], 'specialization_tags' => ['door_lock', 'guide_shoe_insert', 'door_skate', 'elevator_button', 'roller']],
            ['name' => 'МЭЛ', 'aliases' => ['Могилёвский завод лифтового машиностроения', 'Mogilev Elevator'], 'specialization_tags' => ['door_lock', 'guide_shoe_insert', 'elevator_button', 'roller']],
            ['name' => 'КМЗ', 'aliases' => ['Карачаровский механический завод', 'Karacharovo'], 'specialization_tags' => ['door_lock', 'guide_shoe_insert', 'elevator_button', 'roller']],
            ['name' => 'КЛЗ', 'aliases' => ['Курганский лифтостроительный завод'], 'specialization_tags' => ['door_lock', 'guide_shoe_insert', 'roller']],
            ['name' => 'Wellmaks', 'aliases' => ['Велмакс'], 'specialization_tags' => ['door_lock', 'guide_shoe_insert', 'elevator_button', 'roller']],

            // Азиатские OEM эскалаторов/траволаторов
            ['name' => 'Canny', 'aliases' => ['CANNY'], 'specialization_tags' => ['escalator_traction_chain', 'escalator_step', 'controller']],
            ['name' => 'Hyundai', 'aliases' => ['Hyundai Elevator'], 'specialization_tags' => ['escalator_traction_chain', 'escalator_step', 'controller']],
            ['name' => 'Toshiba', 'aliases' => [], 'specialization_tags' => ['escalator_traction_chain', 'escalator_step', 'controller']],
            ['name' => 'LG', 'aliases' => ['LG-Otis', 'Sigma'], 'specialization_tags' => ['escalator_traction_chain', 'escalator_step', 'controller']],
            ['name' => 'Fujitec', 'aliases' => [], 'specialization_tags' => ['escalator_traction_chain', 'escalator_step', 'controller']],
        ];

        foreach ($brands as $b) {
            ManufacturerBrand::updateOrCreate(
                ['name' => $b['name']],
                [
                    'aliases' => $b['aliases'],
                    'specialization_tags' => $b['specialization_tags'],
                    'is_active' => true,
                ]
            );
        }
    }
}
