<?php

namespace App\Services\Kb;

use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\ManufacturerBrand;
use App\Models\Kb\RequestContext;
use App\Models\RequestItem;

/**
 * Документ 3 §4.4: определение manufacturer_brand_id для позиции.
 */
class BrandResolutionService
{
    /**
     * @param array<string,mixed> $availableParameters Параметры, собранные QA в текущей оценке
     *        (extracted_parameters + inherited + parsed). Используется как fallback к
     *        item->parsed_brand: lift_brand часто содержит OEM лифта (ЩЛЗ, МЭЛ, OTIS и т.д.),
     *        и для запчастей где OEM=производитель этого хватает.
     * @param RequestContext|null $context Контекст заявки. Если null — fallback к
     *        $item->request->context (старый путь, для admin RequestItem). Передаётся
     *        явно из NeedAssessmentService для transient Cabinet-flow, где у $item нет
     *        связанного Request в БД.
     */
    public function resolve(
        RequestItem $item,
        ?EquipmentCategory $category,
        array $availableParameters = [],
        ?RequestContext $context = null
    ): ?int {
        // 1. parsed_brand с матчингом по name/aliases (case-insensitive)
        $parsedBrand = trim((string) ($item->parsed_brand ?? ''));
        if ($parsedBrand !== '') {
            $brand = $this->findBrandByNameOrAlias($parsedBrand);
            if ($brand) {
                return $brand->id;
            }
        }

        // 2. lift_brand из available_parameters — OEM лифта (часто = производитель запчасти
        //    для ЩЛЗ/МЭЛ/КМЗ/OTIS, сами производящих двери, замки, башмаки и т.д.)
        $liftBrand = trim((string) ($availableParameters['lift_brand'] ?? ''));
        if ($liftBrand !== '') {
            $brand = $this->findBrandByNameOrAlias($liftBrand);
            if ($brand) {
                return $brand->id;
            }
        }

        // 3. Из контекста заявки — единица оборудования, к которой привязана позиция
        if ($item->equipment_unit_id !== null) {
            if ($context === null) {
                $request = $item->request()->with('context')->first();
                $context = $request?->context;
            }
            if ($context) {
                $unit = $context->findUnit($item->equipment_unit_id);
                $unitBrandName = $unit['brand'] ?? null;
                if (is_string($unitBrandName) && $unitBrandName !== '') {
                    $brand = $this->findBrandByNameOrAlias($unitBrandName);
                    if ($brand) {
                        return $brand->id;
                    }
                }
            }
        }

        // 4. Если категория имеет ровно один типичный бренд по specialization_tags
        if ($category) {
            $candidates = ManufacturerBrand::active()
                ->whereJsonContains('specialization_tags', $category->slug)
                ->get();
            if ($candidates->count() === 1) {
                return $candidates->first()->id;
            }
        }

        return null;
    }

    private function findBrandByNameOrAlias(string $needle): ?ManufacturerBrand
    {
        $normalized = mb_strtolower(trim($needle));

        // Точное по name
        $brand = ManufacturerBrand::active()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();
        if ($brand) {
            return $brand;
        }

        // По aliases (jsonb)
        $brands = ManufacturerBrand::active()->get();
        foreach ($brands as $b) {
            $aliases = $b->aliases ?? [];
            foreach ($aliases as $a) {
                if (is_string($a) && mb_strtolower(trim($a)) === $normalized) {
                    return $b;
                }
            }
        }

        // Подстрока по name
        $brand = ManufacturerBrand::active()
            ->whereRaw('LOWER(name) LIKE ?', ['%' . $normalized . '%'])
            ->first();

        return $brand;
    }
}
