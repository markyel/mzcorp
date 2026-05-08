<?php

namespace App\Prompts\Kb;

/**
 * Документ 2 §6: промпт LLM для извлечения контекста заявки целиком.
 *
 * Возвращает массив сообщений Chat Completions API.
 */
class RequestContextAnalysisPrompt
{
    /**
     * @param array<int, array{parsed_name?: string, parsed_qty?: float|int, parsed_unit?: string}> $itemsBrief
     * @return array<int, array{role: string, content: string}>
     */
    public static function build(string $emailBody, ?string $emailSubject, array $itemsBrief): array
    {
        return [
            [
                'role' => 'system',
                'content' => self::systemMessage(),
            ],
            [
                'role' => 'user',
                'content' => self::userMessage($emailBody, $emailSubject, $itemsBrief),
            ],
        ];
    }

    private static function systemMessage(): string
    {
        return <<<'TXT'
Ты — ассистент по анализу заявок на лифтовые и эскалаторные запчасти.
Твоя задача — извлечь из текста письма заявки **общий контекст** заявки
(не разбирать отдельные позиции, это уже сделано).

Анализируешь:
1. Какие единицы оборудования (лифты, эскалаторы, траволаторы) упомянуты
   и каковы их характеристики (марка, модель, серия, объект).
2. Из каких источников могут быть взяты артикулы (упомянутые поставщики,
   URL каталогов, форварды чужих КП).
3. Срочность, желаемые сроки, особые условия.
4. Если в заявке несколько единиц оборудования и есть явные привязки
   позиций к ним — указать.

Возвращай **строго JSON** в формате, указанном пользователем. Никакого
markdown, никакого комментария, только JSON.

Не выдумывай:
- Если марка лифта не упомянута — brand = null, а не "вероятно OTIS".
- Если серия лифта названа неоднозначно — раскрывай в `raw_mention`,
  но в `series` ставь null.
- Если в заявке нет нескольких лифтов — единиц оборудования может быть 0 или 1.
- Если ничего из названного не упомянуто (письмо состоит только из списка позиций
  без шапки) — возвращай пустые массивы, это нормально.

Расшифровка букв в обозначениях лифтов (для справки):
- H = Hydraulic (гидравлический)
- E = Electric (электрический с редуктором)
- VF = Variable Frequency (частотный)
- MR = Machine Room (с машинным помещением)
- MRL = Machine Room-Less (без машинного помещения)
- R = Russian (модель для рынка СНГ)

Применяй эти расшифровки в поле drive_type только при явной маркировке.

Формат ожидаемого JSON:
{
  "equipment_units": [
    {
      "id": "unit_1",
      "type": "lift" | "escalator" | "traveler",
      "label": "Лифт №1",
      "brand": "OTIS" | null,
      "model": "2000R" | null,
      "series": null,
      "drive_type": "VF" | null,
      "object_address": "Тверская-15" | null,
      "capacity_kg": 630 | null,
      "speed_mps": 1.0 | null,
      "stops_count": 14 | null,
      "raw_mention": "точная цитата из письма",
      "confidence": 0.95
    }
  ],
  "mentioned_sources": [
    {
      "type": "supplier_name" | "url" | "forwarded_quote",
      "value": "...",
      "context": "точная цитата для аудита"
    }
  ],
  "metadata": {
    "urgency": "high" | "normal" | "low" | null,
    "desired_delivery_date": "ISO date" | null,
    "special_conditions": ["original_only" | "certificate_required" | "analog_acceptable"],
    "raw_urgency_mention": "..." | null
  },
  "position_to_unit_assignments": [
    {"position_index": 0, "unit_id": "unit_1"}
  ]
}

Если поле не определено — null или пустой массив, в зависимости от типа.
position_to_unit_assignments возвращай только для тех позиций, где привязка
ОДНОЗНАЧНА из текста (явное упоминание "для лифта 1", разделитель "По лифту 1:"
выше группы позиций). Не угадывай по типу запчасти — это сделает другая система.
TXT;
    }

    /**
     * @param array<int, array<string, mixed>> $itemsBrief
     */
    private static function userMessage(string $emailBody, ?string $emailSubject, array $itemsBrief): string
    {
        $lines = [];
        $lines[] = 'ТЕМА: ' . ($emailSubject ?? '(без темы)');
        $lines[] = '';
        $lines[] = 'ТЕКСТ ПИСЬМА:';
        $lines[] = $emailBody !== '' ? $emailBody : '(пустое тело)';
        $lines[] = '';
        $lines[] = 'ПОЗИЦИИ ИЗ ЗАЯВКИ (для контекста, разбирать их детально не нужно):';
        if (empty($itemsBrief)) {
            $lines[] = '(нет распарсенных позиций)';
        } else {
            foreach ($itemsBrief as $idx => $it) {
                $name = trim((string) ($it['parsed_name'] ?? ''));
                $qty = $it['parsed_qty'] ?? '';
                $unit = $it['parsed_unit'] ?? '';
                $lines[] = sprintf('%d: %s — %s %s', $idx, $name, $qty, $unit);
            }
        }
        $lines[] = '';
        $lines[] = 'Извлеки контекст согласно формату.';

        return implode("\n", $lines);
    }
}
