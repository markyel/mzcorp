<?php

namespace App\Services\Kb;

use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\IdentificationParameter;
use App\Models\Kb\IdentificationRule;

/**
 * Документ 3 §4.6: сверка доступных параметров с правилами идентификации.
 */
class RuleEvaluationService
{
    /**
     * @param array<string, mixed> $availableParameters
     * @return array{
     *   applied_rule_id: int|null,
     *   alternatives: array<int, array<string, mixed>>,
     *   is_sufficient: bool,
     *   best_alternative_to_pursue: int|null,
     *   reason?: string
     * }
     */
    public function evaluate(EquipmentCategory $category, ?int $brandId, array $availableParameters): array
    {
        $rules = IdentificationRule::query()
            ->where('is_active', true)
            ->where('category_id', $category->id)
            ->orderBy('priority')
            ->with('alternatives')
            ->get();

        // Фильтр по applies_to_brands
        $applicable = $rules->filter(function (IdentificationRule $r) use ($brandId) {
            $brands = $r->applies_to_brands;
            if (empty($brands) || !is_array($brands)) {
                return true; // null/пусто = универсальное правило
            }
            return $brandId !== null && in_array($brandId, $brands, true);
        })->values();

        $rule = $applicable->first();
        if (!$rule) {
            return [
                'applied_rule_id' => null,
                'alternatives' => [],
                'is_sufficient' => false,
                'best_alternative_to_pursue' => null,
                'reason' => 'no_rule_applies',
            ];
        }

        // Собрать все нужные параметры одним запросом
        $allParamIds = collect($rule->alternatives)
            ->flatMap(fn ($a) => $a->required_parameter_ids ?? [])
            ->unique()
            ->values()
            ->all();

        $paramsById = IdentificationParameter::whereIn('id', $allParamIds)
            ->get()
            ->keyBy('id');

        $altResults = [];
        foreach ($rule->alternatives as $alt) {
            $required = collect($alt->required_parameter_ids ?? [])
                ->map(fn ($id) => $paramsById->get($id))
                ->filter();

            $requiredSlugs = $required->pluck('slug')->all();
            $missing = [];
            foreach ($required as $param) {
                $slug = $param->slug;
                if (!array_key_exists($slug, $availableParameters)) {
                    $missing[] = $slug;
                    continue;
                }
                $value = $availableParameters[$slug];
                if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                    $missing[] = $slug;
                }
            }

            $altResults[] = [
                'id' => $alt->id,
                'label' => $alt->label,
                'preference_order' => $alt->preference_order,
                'required_slugs' => $requiredSlugs,
                'missing' => $missing,
                'covered' => empty($missing),
            ];
        }

        $isSufficient = collect($altResults)->contains(fn ($a) => $a['covered'] === true);

        $best = null;
        if (!$isSufficient && !empty($altResults)) {
            // Сортировка best_to_pursue:
            //  1) preference_order (lower = higher priority) — явная подсказка куратора
            //     «спрашивай этих параметров, а не других»
            //  2) кол-во missing — tiebreaker, при равном priority берём ту,
            //     которую быстрее закрыть
            $sorted = collect($altResults)->sortBy([
                fn ($a, $b) => $a['preference_order'] <=> $b['preference_order'],
                fn ($a, $b) => count($a['missing']) <=> count($b['missing']),
            ])->values();
            $best = $sorted->first()['id'] ?? null;
        }

        return [
            'applied_rule_id' => $rule->id,
            'alternatives' => $altResults,
            'is_sufficient' => $isSufficient,
            'best_alternative_to_pursue' => $best,
        ];
    }
}
