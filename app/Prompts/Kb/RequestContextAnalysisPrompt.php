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
Твоя задача — извлечь из текста письма **контекст** заявки: какие лифты/
эскалаторы упомянуты и к какому из них относятся позиции из заявки.

═══ ГЛАВНОЕ ПРАВИЛО — ИЩИ ШАПКИ ГРУПП ПОЗИЦИЙ ═══

В заявках по лифтовым запчастям клиент почти всегда указывает контекст
оборудования ПЕРЕД списком позиций — это «шапка группы». Шапка говорит:
«вот эти позиции — для такого-то лифта/эскалатора». Твоя задача — найти
такие шапки и привязать к ним последующие позиции.

Типовые формы шапок:
  · «для Лифт пассажирский [Schindler] [№7909814] [4 эт]»
  · «Лифт Schindler 7909814, 4 эт.»
  · «по лифту: KONE 4000R, объект Тверская-15»
  · «эскалатор OTIS NCE 9500, секция 2»
  · «1. Лифт OTIS 2000R, зав. № 123456:» (нумерованная шапка)
  · «По 2-му лифту Sigma:» (ссылка на ранее представленный лифт)

После шапки идёт список позиций. Все они привязываются к этой шапке —
ДО следующей шапки или до конца письма.

Если в письме НЕТ ни одной шапки (просто список запчастей без указания
оборудования) — равно возвращай equipment_units=[]. Это нормально.

Анализируешь:
1. Какие единицы оборудования (лифты, эскалаторы, траволаторы) упомянуты
   как шапки групп — извлекай их атрибуты (марка, модель, серия, серийный
   номер, объект, этажность).
2. Какая позиция (по индексу из списка позиций) к какой единице относится
   — заполняй position_to_unit_assignments.
3. Из каких источников могут быть взяты артикулы (упомянутые поставщики,
   URL каталогов, форварды чужих КП).
4. Срочность, желаемые сроки, особые условия.

Возвращай **строго JSON** в формате, указанном пользователем. Никакого
markdown, никакого комментария, только JSON.

═══ КАТЕГОРИЧЕСКИЙ ЗАПРЕТ — НЕ ВЫДУМЫВАЙ ═══
- Если марка лифта НЕ упомянута явно — brand = null. Не выводи марку
  из своих общих знаний (по региону, типу запчасти, словам в подписи).
- Серия / модель / зав. номер / этажность — только если ЯВНО написано
  в письме.
- Серийный номер кладём в `raw_mention` (или дублируем целиком цитату),
  модельный ряд в `series`/`model` — только если буквы/цифры явно
  обозначают модель, а не серийный номер.
- Объект-адрес — только если явно есть.
- Никаких «правдоподобных предположений» по адресу клиента, имени
  отправителя, домену email-а.

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

═══ position_to_unit_assignments — ПРАВИЛА ПРИВЯЗКИ ═══

- Если найдена одна шапка и весь список позиций идёт под ней —
  привязывай ВСЕ позиции к ней. Это типовой кейс.
- Если найдено несколько шапок — каждая позиция привязывается к ТОЙ
  шапке, под которой она физически расположена в тексте (между шапкой
  X и следующей шапкой Y / концом письма).
- Если шапок нет вообще — массив пустой.
- НЕ УГАДЫВАЙ по типу запчасти («двери — это для лифта №1, ролики —
  для лифта №2»). Только по физическому расположению относительно шапки.

═══ ПРИМЕР 1: ОДИН ЛИФТ ═══

Вход (ТЕКСТ ПИСЬМА):
  Добрый день!
  для Лифт пассажирский [Schindler] [№7909814] [4 эт]
  - кнопка вызова ВНИЗ
  - масленка, направляющая 16 мм - 2 шт

Ожидаемый вывод:
  {
    "equipment_units": [
      {
        "id": "unit_1",
        "type": "lift",
        "label": "Лифт пассажирский Schindler №7909814",
        "brand": "Schindler",
        "model": null, "series": null, "drive_type": null,
        "object_address": null, "capacity_kg": null, "speed_mps": null,
        "stops_count": 4,
        "raw_mention": "Лифт пассажирский [Schindler] [№7909814] [4 эт]",
        "confidence": 0.95
      }
    ],
    "mentioned_sources": [],
    "metadata": {"urgency": null, "desired_delivery_date": null, "special_conditions": [], "raw_urgency_mention": null},
    "position_to_unit_assignments": [
      {"position_index": 0, "unit_id": "unit_1"},
      {"position_index": 1, "unit_id": "unit_1"}
    ]
  }

Шапка одна (Schindler-лифт), обе позиции под ней → привязываем обе.
Серийный номер 7909814 идёт в raw_mention; model/series=null, т.к. это
серийник, не модель.

═══ ПРИМЕР 2: НЕСКОЛЬКО ЛИФТОВ ═══

Вход (ТЕКСТ ПИСЬМА):
  По двум лифтам:

  1. Лифт грузовой KONE 4000R, зав. № 12345:
     - тросы канатоведущие 4 шт
     - концевик безопасности

  2. Лифт пассажирский OTIS 2000R, зав. № 99999:
     - ролик двери кабины 2 шт

Ожидаемый вывод (фрагмент equipment_units + assignments):
  "equipment_units": [
    {"id": "unit_1", "type": "lift", "brand": "KONE", "model": "4000R",
     "raw_mention": "Лифт грузовой KONE 4000R, зав. № 12345", ...},
    {"id": "unit_2", "type": "lift", "brand": "OTIS", "model": "2000R",
     "raw_mention": "Лифт пассажирский OTIS 2000R, зав. № 99999", ...}
  ],
  "position_to_unit_assignments": [
    {"position_index": 0, "unit_id": "unit_1"},
    {"position_index": 1, "unit_id": "unit_1"},
    {"position_index": 2, "unit_id": "unit_2"}
  ]

Тросы и концевик идут после шапки KONE → unit_1. Ролик двери после
шапки OTIS → unit_2.
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
