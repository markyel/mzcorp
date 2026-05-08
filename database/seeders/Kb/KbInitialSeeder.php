<?php

namespace Database\Seeders\Kb;

use Illuminate\Database\Seeder;

/**
 * KB §7.1: корневой сидер для модуля оценки качества заявок.
 *
 * Запуск: php artisan db:seed --class="Database\\Seeders\\Kb\\KbInitialSeeder"
 *
 * parameter_extractors частично сидируются базовыми кейсами
 * (RopeExtractorsSeeder — маркировка тяговых канатов по ГОСТам),
 * остальное наполняется по мере накопления знаний куратором.
 */
class KbInitialSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ManufacturerBrandsSeeder::class,
            BrandSkuPatternsSeeder::class,
            EquipmentCategoriesSeeder::class,
            EquipmentCategoryCoarseSeeder::class,
            IdentificationParametersSeeder::class,
            IdentificationRulesSeeder::class,
            RopeExtractorsSeeder::class,
            DoorSkateExtractorsSeeder::class,
            ContactorExtractorsSeeder::class,
            GlobalExtractorsSeeder::class,
        ]);
    }
}
