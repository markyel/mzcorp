<?php

namespace Database\Seeders\Kb;

use App\Models\Kb\ParameterExtractor;
use Illuminate\Database\Seeder;

/**
 * Глобальные экстракторы — без привязки к категории, бренду или SKU-паттерну.
 *
 * Сейчас содержит экстрактор lift_brand: ловит явное упоминание известного OEM
 * в parsed_name. Используется когда n8n-парсер не извлёк brand отдельным полем,
 * но текст позиции содержит название (например, «Ролик тросика связи, ЩЛЗ, ...»).
 *
 * Идемпотентен: updateOrCreate по (category_id=null, source_field, brand_id=null,
 * triggered_by_sku_pattern_id=null).
 */
class GlobalExtractorsSeeder extends Seeder
{
    public function run(): void
    {
        // Список известных OEM имён — synced с ManufacturerBrandsSeeder.
        // Только канонические name + русские/латинские варианты, чтобы регулярка
        // не конфликтовала с aliases в БД (BrandResolutionService::findBrandByNameOrAlias
        // потом сматчит на ManufacturerBrand сам).
        $brandPatterns = [
            // Российские/СНГ
            'ЩЛЗ', 'МЭЛ', 'КМЗ', 'КЛЗ',
            // Западные
            'OTIS', 'KONE', 'Schindler', 'ThyssenKrupp', 'TKE',
            'Wittur', 'Fermator', 'Selcom', 'Sematic',
            // Азиатские
            'XIZI OTIS', 'Hyundai', 'Toshiba', 'Mitsubishi', 'Fujitec', 'Canny', 'SJEC',
            // Электрика/привод
            'Schneider', 'Siemens', 'ABB', 'Vacon', 'Yaskawa',
        ];

        // Word boundary + любой регистр. Используем (?:...) non-capturing для альтернатив,
        // и захватываем основу в (...) — extractor возвращает $matches[1].
        // Сортируем по длине убыванию чтобы «XIZI OTIS» матчился раньше «OTIS».
        usort($brandPatterns, fn ($a, $b) => strlen($b) <=> strlen($a));
        $alts = implode('|', array_map(fn ($b) => preg_quote($b, '/'), $brandPatterns));

        $pattern = '\\b(' . $alts . ')\\b';

        ParameterExtractor::updateOrCreate(
            [
                'category_id' => null,
                'source_field' => 'name',
                'brand_id' => null,
                'triggered_by_sku_pattern_id' => null,
            ],
            [
                'rules' => [
                    [
                        'parameter_slug' => 'lift_brand',
                        'patterns' => [$pattern],
                    ],
                ],
                'test_examples' => [
                    [
                        'input' => 'Ролик тросика связи, ЩЛЗ, D - 70 мм',
                        'expected' => ['lift_brand' => 'ЩЛЗ'],
                    ],
                    [
                        'input' => 'Ролик поручня эскалатора D60x55мм, XAA290CZ, XIZI OTIS 508',
                        'expected' => ['lift_brand' => 'XIZI OTIS'],
                    ],
                    [
                        'input' => 'Контактор Schneider LC1D188',
                        'expected' => ['lift_brand' => 'Schneider'],
                    ],
                ],
                'is_active' => true,
            ]
        );
    }
}
