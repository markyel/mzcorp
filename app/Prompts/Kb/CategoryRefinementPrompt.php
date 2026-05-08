<?php

namespace App\Prompts\Kb;

use App\Models\Kb\EquipmentCategory;
use App\Models\RequestItem;
use Illuminate\Support\Collection;

/**
 * Документ 3 §4.3: промпт LLM для уточнения детальной категории.
 *
 * Список кандидатов собирается динамически из БД (для конкретной грубой категории).
 */
class CategoryRefinementPrompt
{
    /**
     * @param Collection<int, EquipmentCategory> $candidates
     * @param array<string, mixed> $extractedParameters
     * @return array<int, array{role: string, content: string}>
     */
    public static function build(
        RequestItem $item,
        Collection $candidates,
        ?string $brandName,
        array $extractedParameters
    ): array {
        return [
            ['role' => 'system', 'content' => self::systemMessage($candidates)],
            ['role' => 'user', 'content' => self::userMessage($item, $brandName, $extractedParameters)],
        ];
    }

    /**
     * @param Collection<int, EquipmentCategory> $candidates
     */
    private static function systemMessage(Collection $candidates): string
    {
        $catLines = $candidates->map(function (EquipmentCategory $c) {
            $synonyms = is_array($c->synonyms) ? implode(', ', $c->synonyms) : '';
            $desc = $c->description ?: '';
            return sprintf("- %s: %s. Синонимы: %s. %s", $c->slug, $c->name, $synonyms, $desc);
        })->implode("\n");

        return <<<TXT
Ты — классификатор позиций лифтовых заявок. Получаешь название позиции
и список возможных детальных категорий. Возвращаешь slug категории,
к которой позиция относится с наибольшей уверенностью.

Категории-кандидаты:
{$catLines}

Возвращай строго JSON: {"category_slug": "...", "confidence": 0.0-1.0, "reasoning": "..."}.
Если ни одна категория не подходит — confidence = 0, category_slug = null.
TXT;
    }

    /**
     * @param array<string, mixed> $extractedParameters
     */
    private static function userMessage(RequestItem $item, ?string $brandName, array $extractedParameters): string
    {
        $lines = [];
        $lines[] = 'Позиция:';
        $lines[] = '  parsed_name: ' . trim((string) $item->parsed_name);
        if ($item->parsed_article) {
            $lines[] = '  parsed_article: ' . $item->parsed_article;
        }
        if ($item->raw_text) {
            $lines[] = '  raw_text: ' . trim((string) $item->raw_text);
        }
        if ($brandName) {
            $lines[] = '  brand: ' . $brandName;
        }
        if (!empty($extractedParameters)) {
            $lines[] = '  extracted_parameters: ' . json_encode($extractedParameters, JSON_UNESCAPED_UNICODE);
        }
        $lines[] = '';
        $lines[] = 'Определи slug категории.';
        return implode("\n", $lines);
    }
}
