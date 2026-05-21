<?php

namespace App\Prompts\Kb;

/**
 * Vision-классификатор фоток по KB photo-slot'ам (2026-05-21).
 *
 * Цель: для каждой фотки треда определить какой photo-slug категории
 * позиции она представляет (фото шильдика, лицевой стороны, общего вида,
 * паспорта и т.п.). Результат заполняет request_items.quality_assessment_payload
 * .extracted_parameters[photo_*] = true и EmailAttachment.metadata.kb_slot_candidates.
 *
 * Вход: список ожидаемых photo-slug'ов категории + N изображений по порядку.
 * Выход: для каждой image_index — какой slug подошёл (или null), confidence,
 * краткое описание что видно.
 */
class PhotoClassifierPrompt
{
    /**
     * @param  array<int, array{slug: string, name: string, question_template: ?string}>  $photoSlots
     * @param  array<int, string>  $imagesBase64   data:image/jpeg;base64,... или URL
     * @param  array{parsed_name?: string, parsed_brand?: string, parsed_article?: string, category_name?: string}  $itemContext
     * @return array<int, array{role: string, content: mixed}>
     */
    public static function build(array $photoSlots, array $imagesBase64, array $itemContext): array
    {
        $userContent = [
            ['type' => 'text', 'text' => self::userTextBlock($photoSlots, $itemContext)],
        ];
        foreach ($imagesBase64 as $img) {
            $userContent[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $img,
                    'detail' => 'high', // важно для чтения шильдиков/маркировок
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
Ты — Vision-классификатор фотографий, приложенных к заявке на лифтовую
запчасть. Менеджер прикрепил пачку фото, и тебе нужно определить, какие
из них — фото шильдика, какие — лицевой стороны изделия, какие — общий
вид, и т.д.

ВХОД:
1. Список ожидаемых photo-слотов для текущей категории позиции — у
   каждого slug, человеческое имя и question_template (что мы обычно
   просим у клиента под этим слотом).
2. Контекст позиции: что заказал клиент (название, бренд, артикул).
3. Несколько изображений по порядку (image_index = 0, 1, 2, ...).

ЗАДАЧА: для КАЖДОГО изображения определить:
  - Подходит ли оно под один из ожидаемых photo-slots?
  - Если да — какой именно slug, с какой уверенностью.
  - Что конкретно видно (короткое описание для аудита и менеджера).

ПРАВИЛА:
- Одно изображение может подходить РОВНО под один slug (выбирай лучший
  по смыслу — если на фото и шильдик и общий вид, приоритет — шильдик,
  потому что он информативнее).
- Если фото вообще не относится к товару (палец, пол, кабина лифта
  издалека) — slug=null, status="irrelevant", описание короткое.
- Если фото товара, но не подходит ни под один из ожидаемых slug'ов —
  slug=null, status="other", описание что на фото.
- Сразу несколько фоток могут попасть под один slug (клиент прислал
  несколько ракурсов шильдика) — это нормально, всем им проставляй
  тот же slug.
- НЕ ВЫДУМЫВАЙ: если на фото не видно надписей/маркировок, не «угадывай»
  что это шильдик. Бери только то, что РЕАЛЬНО видно.
- confidence: 0.0–1.0. Выше 0.9 — однозначное соответствие. 0.6–0.9 —
  скорее да, но есть вариативность. Ниже 0.6 — НЕ возвращай slug,
  ставь null.

ВЫХОДНОЙ JSON (строго, без markdown):
{
  "classifications": [
    {
      "image_index": 0,
      "slug": "photo_nameplate" | null,
      "confidence": 0.0,
      "status": "matched" | "irrelevant" | "other",
      "description": "виден шильдик Schindler с артикулом SCH_55502867"
    },
    ...
  ]
}

Если нет ни одного полезного фото — верни classifications: [].
TXT;
    }

    /**
     * @param  array<int, array{slug: string, name: string, question_template: ?string}>  $photoSlots
     * @param  array<string, mixed>  $itemContext
     */
    private static function userTextBlock(array $photoSlots, array $itemContext): string
    {
        $lines = [];
        $lines[] = 'КОНТЕКСТ ПОЗИЦИИ:';
        $lines[] = '  название: '.((string) ($itemContext['parsed_name'] ?? '(не задано)'));
        if (! empty($itemContext['parsed_brand'])) {
            $lines[] = '  бренд: '.$itemContext['parsed_brand'];
        }
        if (! empty($itemContext['parsed_article'])) {
            $lines[] = '  артикул: '.$itemContext['parsed_article'];
        }
        if (! empty($itemContext['category_name'])) {
            $lines[] = '  категория: '.$itemContext['category_name'];
        }
        $lines[] = '';
        $lines[] = 'ОЖИДАЕМЫЕ PHOTO-СЛОТЫ ЭТОЙ КАТЕГОРИИ:';
        if (empty($photoSlots)) {
            $lines[] = '  (нет специфичных photo-слотов — используй только photo_general если он есть)';
        } else {
            foreach ($photoSlots as $slot) {
                $line = '  · '.$slot['slug'].' — '.$slot['name'];
                if (! empty($slot['question_template'])) {
                    $line .= '. Что мы просим: '.mb_substr((string) $slot['question_template'], 0, 200);
                }
                $lines[] = $line;
            }
        }
        $lines[] = '';
        $lines[] = 'НИЖЕ — изображения (image_index = 0, 1, 2, ...). Классифицируй каждое.';

        return implode("\n", $lines);
    }
}
