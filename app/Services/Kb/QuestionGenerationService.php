<?php

namespace App\Services\Kb;

use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\IdentificationParameter;
use App\Models\Kb\ManufacturerBrand;
use App\Models\RequestItem;

/**
 * Документ 3 §4.7: формирование уточняющих вопросов для best alternative.
 *
 * Группировка вопросов между позициями (например, "по позициям 1,3,5 укажите цвет")
 * — задача документа 4 (UI отправки уточнений).
 */
class QuestionGenerationService
{
    /**
     * @param array<string, mixed> $evaluation Результат RuleEvaluationService::evaluate
     * @return array<int, array<string, mixed>>
     */
    public function generate(
        RequestItem $item,
        EquipmentCategory $category,
        ?int $brandId,
        array $evaluation
    ): array {
        $bestId = $evaluation['best_alternative_to_pursue'] ?? null;
        if ($bestId === null) {
            return [];
        }

        $alt = collect($evaluation['alternatives'] ?? [])->firstWhere('id', $bestId);
        if (!$alt) {
            return [];
        }

        $missingSlugs = $alt['missing'] ?? [];
        if (empty($missingSlugs)) {
            return [];
        }

        $params = IdentificationParameter::whereIn('slug', $missingSlugs)->get();

        $brandName = $brandId ? ManufacturerBrand::find($brandId)?->name : null;
        $context = [
            'position_number' => (int) ($item->position ?? 0),
            'item_name' => (string) $item->parsed_name,
            'brand' => $brandName ?? '',
            'category_name' => $category->name,
        ];

        $questions = [];
        foreach ($params as $param) {
            $text = $this->renderTemplate($param->question_template, $context);
            $questions[] = [
                'parameter_slug' => $param->slug,
                'parameter_id' => $param->id,
                'question_text' => $text,
                'value_type' => $param->value_type,
                'allowed_values' => $param->allowed_values ?? [],
                'unit' => $param->unit,
            ];
        }

        return $questions;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderTemplate(string $template, array $context): string
    {
        $result = $template;
        foreach ($context as $key => $value) {
            $result = str_replace('{' . $key . '}', (string) $value, $result);
        }
        return $result;
    }
}
