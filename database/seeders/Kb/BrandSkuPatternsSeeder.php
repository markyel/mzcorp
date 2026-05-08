<?php

namespace Database\Seeders\Kb;

use App\Models\Kb\BrandSkuPattern;
use App\Models\Kb\ManufacturerBrand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * KB §7.3: известные маски артикулов производителей.
 */
class BrandSkuPatternsSeeder extends Seeder
{
    public function run(): void
    {
        $patterns = [
            // OTIS
            ['brand' => 'OTIS', 'pattern' => '^GAA\d{3}[A-Z]\d{1,2}$', 'series_name' => 'GAA-серия (механика кабины)', 'priority' => 50],
            ['brand' => 'OTIS', 'pattern' => '^FAA\d{3,5}[A-Z]{1,3}\d{0,2}$', 'series_name' => 'FAA-серия (двери)', 'priority' => 50],
            ['brand' => 'OTIS', 'pattern' => '^XAA\d{3}[A-Z]{1,3}\d{0,2}$', 'series_name' => 'XAA-серия (эскалаторы)', 'priority' => 50],
            ['brand' => 'OTIS', 'pattern' => '^XAB\d{3}[A-Z]{1,3}\d{0,2}$', 'series_name' => 'XAB-серия (эскалаторы)', 'priority' => 50],
            ['brand' => 'OTIS', 'pattern' => '^ZAA\d{3,5}[A-Z]{1,3}\d{0,2}$', 'series_name' => 'ZAA-серия (общая)', 'priority' => 50],
            ['brand' => 'OTIS', 'pattern' => '^DAA\d{3,5}[A-Z]{1,3}\d{0,2}$', 'series_name' => 'DAA-серия (контроллеры)', 'priority' => 50],
            ['brand' => 'OTIS', 'pattern' => '^TAA\d{3,5}[A-Z]{1,3}\d{0,2}$', 'series_name' => 'TAA-серия (механика)', 'priority' => 50],

            // Schneider
            ['brand' => 'Schneider', 'pattern' => '^LC1[DF]\d{2}[A-Z]\d{1,2}$', 'series_name' => 'LC1D/LC1F (контакторы)', 'priority' => 50],

            // Schindler
            ['brand' => 'Schindler', 'pattern' => '^59\d{6}$', 'series_name' => 'Variodyn / BIONIC (8-значные)', 'priority' => 50],
            ['brand' => 'Schindler', 'pattern' => '^ID\.NR\.\d{6}$', 'series_name' => 'ID.NR. формат', 'priority' => 60],

            // Fermator (отводки и приводы дверей)
            ['brand' => 'Fermator', 'pattern' => '^CDL[-‐]?P\d{3}[A-Z]{2}\d{3,4}$', 'series_name' => 'CDL-P (отводки)', 'priority' => 50],
        ];

        foreach ($patterns as $p) {
            $brand = ManufacturerBrand::where('name', $p['brand'])->first();
            if (!$brand) {
                Log::warning("BrandSkuPatternsSeeder: brand not found", ['name' => $p['brand']]);
                continue;
            }

            BrandSkuPattern::updateOrCreate(
                [
                    'brand_id' => $brand->id,
                    'pattern' => $p['pattern'],
                ],
                [
                    'series_name' => $p['series_name'],
                    'priority' => $p['priority'],
                    'is_active' => true,
                ]
            );
        }
    }
}
