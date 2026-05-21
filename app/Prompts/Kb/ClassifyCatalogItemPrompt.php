<?php

namespace App\Prompts\Kb;

use App\Models\CatalogItem;
use App\Models\Kb\EquipmentCategory;
use Illuminate\Support\Collection;

/**
 * Prompt для классификации catalog_item в KB EquipmentCategory.
 *
 * Используется в `App\Services\Kb\CatalogItemCategorizer` после того как
 * детерминированный matching по synonyms не дал результата. LLM получает
 * список ВСЕХ активных KB-категорий + поля каталога и выбирает наиболее
 * подходящий ID. Если ни одна не подходит — возвращает null.
 *
 * Модель: gpt-4o-mini (дешёвая, точность достаточная — категорий ~30-40,
 * выбор однозначный по part_type/unit_name).
 *
 * Структурированный JSON-ответ:
 *   { "category_id": int|null, "confidence": 0..1, "reason": "..." }
 *
 * Confidence < 0.6 = неуверенно → не записываем FK (оставляем NULL).
 */
class ClassifyCatalogItemPrompt
{
    /**
     * @return array<int, array{role: string, content: string}>
     */
    public static function build(CatalogItem $item, Collection $categories): array
    {
        $catList = $categories
            ->map(function (EquipmentCategory $c): string {
                $synParts = is_array($c->synonyms) ? $c->synonyms : [];
                $syns = empty($synParts) ? '' : ' [синонимы: ' . implode(', ', array_slice($synParts, 0, 6)) . ']';
                $desc = trim((string) $c->description);
                if ($desc !== '') {
                    $desc = ' — ' . mb_substr($desc, 0, 100);
                }
                return sprintf('  #%d  %s%s%s', $c->id, $c->name, $syns, $desc);
            })
            ->implode("\n");

        $system = <<<SYS
Ты классификатор каталога запчастей лифтового / эскалаторного оборудования.

Задача: выбрать из списка KB-категорий ОДНУ, которая лучше всего описывает тип запчасти каталожной позиции. Если ни одна не подходит — верни category_id = null.

Правила:
1. Опирайся в первую очередь на поле `part_type` (если есть) — это явный тип запчасти из источника. Потом на `name` и `unit_name`.
2. НЕ путай оборудование (лифт/эскалатор/травалатор) с типом детали. «Цепь ступеней эскалатора» = тип «Тяговая цепь эскалатора», а не «Эскалатор».
3. Если позиция — комплект (несколько деталей) или комплектующая часть составного узла — выбирай категорию по ОСНОВНОЙ детали. Пример: «Комплект цепи с роликами и пальцами» → «Тяговая цепь эскалатора».
4. Бренд НЕ влияет на категорию (одна и та же деталь у разных производителей одинакова).
5. Если совсем непонятно — лучше вернуть null чем угадать. Confidence ставь честно.

Ответ строго JSON:
{
  "category_id": <int_id_или_null>,
  "confidence": <0..1>,
  "reason": "<краткое обоснование, 1 предложение>"
}

Доступные KB-категории (id, имя, синонимы, описание):
{$catList}
SYS;

        $catalogJson = json_encode([
            'sku' => $item->sku,
            'name' => $item->name,
            'name_en' => $item->name_en,
            'unit_name' => $item->unit_name,
            'units' => $item->units,
            'placement' => $item->placement,
            'part_type' => $item->part_type,
            'form_factor' => $item->form_factor,
            'brand' => $item->brand,
            'description' => $item->description,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $user = "Каталожная позиция:\n{$catalogJson}\n\nКлассифицируй её. Ответ строго JSON.";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }
}
