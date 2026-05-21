<?php

namespace App\Prompts\Kb;

/**
 * Vision-классификатор фоток по KB photo-slot'ам (2026-05-21, v2 photo-centric).
 *
 * Цель: за ОДИН Vision-вызов распределить все фотки треда заявки между
 * позициями заявки + photo-слотами категорий этих позиций.
 *
 * Раньше (v1) был item-centric — отдельный вызов на каждую позицию,
 * каждая фотка анализировалась N раз. Это давало противоречия и было
 * дорого. v2 даёт модели целостный контекст: «у заявки 3 позиции, вот
 * 8 фоток, разложи их».
 *
 * Вход:
 *   - items: [{position_id, item_index, name, brand, article, category_name, photo_slots: [{slug, name, question_template}]}]
 *   - imagesBase64: [data:image/...;base64,..., ...] в порядке image_index
 *
 * Выход:
 *   {"assignments": [
 *     {"image_index": 0, "item_index": 1, "slug": "photo_nameplate",
 *      "confidence": 0.92, "status": "matched",
 *      "description": "видна табличка кнопки с артикулом SCH-5550287"},
 *     {"image_index": 1, "item_index": null, "slug": null,
 *      "confidence": 0.0, "status": "irrelevant",
 *      "description": "шильдик лифта Schindler, не относится к запчастям"},
 *     ...
 *   ]}
 *
 * Заметки:
 *   - item_index = индекс позиции в items[], НЕ position_id (а сервис сам
 *     мапит item_index → position_id).
 *   - Одна фотка → один item + один slug (модель выбирает лучшее
 *     соответствие; если можно «и шильдик, и общий вид» — приоритет
 *     более информативному).
 */
class PhotoClassifierPrompt
{
    /**
     * @param  array<int, array{position_id: int, item_index: int, name: string, brand: ?string, article: ?string, category_name: ?string, photo_slots: array<int, array{slug: string, name: string, question_template: ?string}>}>  $items
     * @param  array<int, string>  $imagesBase64
     * @return array<int, array{role: string, content: mixed}>
     */
    public static function build(array $items, array $imagesBase64): array
    {
        $userContent = [
            ['type' => 'text', 'text' => self::userTextBlock($items)],
        ];
        foreach ($imagesBase64 as $img) {
            $userContent[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $img,
                    'detail' => 'high', // важно для чтения шильдиков
                ],
            ];
        }

        return [
            ['role' => 'system', 'content' => self::systemMessage()],
            ['role' => 'user', 'content' => $userContent],
        ];
    }

    private static function systemMessage(): string
    {
        return <<<'TXT'
Ты — Vision-классификатор. На вход — заявка на лифтовые запчасти с
несколькими позициями и пачка фотографий, которые клиент приложил.
Твоя задача — за ОДИН проход разложить все фото:
  - какие из них относятся к какой позиции заявки;
  - какому photo-slug этой позиции соответствует (фото шильдика, лицевой
    стороны, общего вида и т.п.) — только из списка ожидаемых slug'ов
    для категории этой позиции;
  - что вообще на фото — для аудита и UI.

═══ ВХОДНЫЕ ДАННЫЕ ═══

Сначала текст: ПОЗИЦИИ ЗАЯВКИ — для каждой указаны item_index, название,
бренд, артикул (если известен), категория, и ОЖИДАЕМЫЕ photo-слоты этой
категории.

Затем картинки в порядке image_index = 0, 1, 2, ... Все они приложены к
одному треду заявки. Часть из них — конкретные фото запчастей. Часть —
шильдики, шильдики лифтов (общая панель), руки, размытые снимки, общая
обстановка кабины. Часть может быть фотками поставщиков/каталогов из
интернета (вставленные клиентом для иллюстрации).

═══ ПРАВИЛА КЛАССИФИКАЦИИ ═══

Для каждой image_index выбери ОДИН из исходов:

1. status="matched" — фото подходит под какой-то photo-slug одной из
   позиций. Заполни:
     · item_index = индекс позиции из массива items (0-based);
     · slug = ровно тот slug, который указан в photo_slots этой позиции;
     · confidence = 0.0-1.0;
     · description = краткое объяснение (1-2 предложения), что видно.

2. status="other" — фото относится к запчасти, но не подходит ни под один
   ожидаемый slug (например фото общего вида запчасти, у которой нет
   слота photo_general). Заполни item_index если можно определить к
   какой позиции; иначе null. slug=null.

3. status="irrelevant" — фото не имеет прямого отношения ни к одной
   позиции: шильдик ЛИФТА в целом (если запчасти-кнопки нет в наличии),
   рука/палец без товара, размытое, общий план кабины. item_index=null,
   slug=null, confidence=0.

═══ ВАЖНЫЕ ПРИНЦИПЫ ═══

- Одна фотка получает ОДИН исход. Если на ней одновременно и шильдик
  кнопки, и кусок платы — выбирай более информативный slot (обычно
  шильдик > общий вид).
- Можно несколько фоток назначать на один slug одной позиции (клиент
  прислал несколько ракурсов шильдика — это нормально).
- Можно несколько фоток назначать на разные позиции (одна заявка → две
  кнопки + одна плата — разложи их).
- НЕ ВЫДУМЫВАЙ: если на фото плохо видно, и нельзя надёжно отнести
  к slug'у — ставь status="other" или confidence ниже 0.6.
- confidence:
    ≥0.9 — однозначное соответствие.
    0.6-0.9 — хорошее, но есть неоднозначность.
    <0.6 — ставь status="other" (модель не уверена).

ВЫХОДНОЙ JSON (строго, без markdown):
{
  "assignments": [
    {
      "image_index": 0,
      "item_index": 1,
      "slug": "photo_nameplate",
      "confidence": 0.92,
      "status": "matched",
      "description": "видна табличка кнопки с артикулом SCH-5550287"
    },
    {
      "image_index": 1,
      "item_index": null,
      "slug": null,
      "confidence": 0.0,
      "status": "irrelevant",
      "description": "шильдик лифта Schindler в кабине, не относится к запчастям"
    }
  ]
}

Если ВСЕ фото irrelevant — верни такой же массив со status="irrelevant"
для каждой. Никогда не возвращай assignments: [].
TXT;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private static function userTextBlock(array $items): string
    {
        $lines = [];
        $lines[] = 'ПОЗИЦИИ ЗАЯВКИ:';
        if (empty($items)) {
            $lines[] = '  (нет позиций с photo-слотами — все фото пометь как irrelevant или other)';
        } else {
            foreach ($items as $it) {
                $idx = (int) ($it['item_index'] ?? 0);
                $lines[] = '';
                $lines[] = '  item_index='.$idx.':';
                $lines[] = '    название: '.((string) ($it['name'] ?? '(без названия)'));
                if (! empty($it['brand'])) {
                    $lines[] = '    бренд: '.$it['brand'];
                }
                if (! empty($it['article'])) {
                    $lines[] = '    артикул: '.$it['article'];
                }
                if (! empty($it['category_name'])) {
                    $lines[] = '    категория: '.$it['category_name'];
                }
                $slots = is_array($it['photo_slots'] ?? null) ? $it['photo_slots'] : [];
                if (empty($slots)) {
                    $lines[] = '    photo-слоты: (нет специфичных — для этой позиции matched ставь только если очень очевидно)';
                } else {
                    $lines[] = '    photo-слоты:';
                    foreach ($slots as $s) {
                        $line = '      · '.$s['slug'].' — '.$s['name'];
                        if (! empty($s['question_template'])) {
                            $line .= '. Что просим: '.mb_substr((string) $s['question_template'], 0, 150);
                        }
                        $lines[] = $line;
                    }
                }
            }
        }
        $lines[] = '';
        $lines[] = 'НИЖЕ — все фотографии треда (image_index = 0, 1, 2, ...). Разложи каждую.';

        return implode("\n", $lines);
    }
}
