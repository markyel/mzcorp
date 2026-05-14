<?php

namespace App\Prompts\Mail;

use App\Models\ClarificationBatch;
use App\Models\EmailMessage;
use App\Models\Request as RequestModel;
use App\Services\Kb\PositionSlotResolver;

/**
 * Foundation §6.2 Phase B/C — LLM-промпт для:
 *  - сматчинга ответа клиента на конкретные вопросы из ClarificationBatch
 *    (заполняет clarification_questions.answer);
 *  - извлечения enrichment suggestions из текста ответа (артикул / бренд /
 *    количество / параметры), которые можно одной кнопкой применить к
 *    parsed_* полям соответствующей позиции.
 *
 * Используем gpt-4o-mini — задача классификационная + копирование, не
 * требует heavy reasoning. confidence-floor 0.6 в коде (не в промпте) —
 * для упрощения промпт всегда выдаёт ответ или null.
 */
class MatchClarificationAnswersPrompt
{
    private const MAX_BODY_CHARS = 8000;

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function build(
        EmailMessage $inbound,
        ClarificationBatch $batch,
        RequestModel $request,
    ): array {
        $body = (string) ($inbound->body_plain ?: strip_tags((string) $inbound->body_html));
        $body = mb_substr(trim($body), 0, self::MAX_BODY_CHARS);

        $batch->loadMissing(['questions.requestItem']);

        $resolver = app(PositionSlotResolver::class);

        // Список вопросов с привязкой к позициям + target_slot_key
        // (если оператор задал вопрос «по конкретному слоту»).
        $questionsBlock = $batch->questions->map(function ($q) {
            $scope = $q->request_item_id
                ? sprintf(
                    'position #%d (%s%s%s)',
                    $q->requestItem?->position ?? 0,
                    trim((string) ($q->requestItem?->parsed_name ?? '?')),
                    $q->requestItem?->parsed_brand ? ', brand: ' . $q->requestItem->parsed_brand : '',
                    $q->requestItem?->parsed_article ? ', article: ' . $q->requestItem->parsed_article : '',
                )
                : 'general (not tied to specific position)';

            return sprintf(
                "- question_id: %d\n  scope: %s\n  question: \"%s\"%s",
                $q->id,
                $scope,
                str_replace('"', '\\"', (string) $q->question),
                $q->target_slot_key
                    ? "\n  target_slot_key: \"" . $q->target_slot_key . "\""
                    : '',
            );
        })->implode("\n");

        // Список всех активных позиций заявки + ДОСТУПНЫЕ СЛОТЫ для
        // enrichment_suggestions. Slots показывают LLM возможные поля
        // куда можно записать значения: brand/article/qty (base) +
        // kb:<slug> для KB-параметров категории (lift_brand, и др.).
        $itemsBlock = $request->items
            ->where('is_active', true)
            ->map(function ($item) use ($resolver) {
                $slots = $resolver->resolve($item);
                $availableSlots = collect($slots)
                    ->filter(fn ($s) => in_array($s['key'], ['brand', 'article', 'qty'], true)
                        || str_starts_with($s['key'], 'kb:'))
                    ->map(fn ($s) => sprintf(
                        '%s ("%s"%s%s)',
                        $s['key'],
                        $s['label'],
                        $s['status'] === 'filled' ? ', already=' . $s['value'] : '',
                        $s['required'] ? ', required' : '',
                    ))
                    ->implode('; ');

                return sprintf(
                    "- item_id: %d, position #%d, name: \"%s\"%s%s%s\n  available_slots: [%s]",
                    $item->id,
                    $item->position,
                    str_replace('"', '\\"', (string) ($item->parsed_name ?? '')),
                    $item->parsed_brand ? ', brand: "' . $item->parsed_brand . '"' : '',
                    $item->parsed_article ? ', article: "' . $item->parsed_article . '"' : '',
                    $item->parsed_qty ? ', qty: ' . rtrim(rtrim((string) $item->parsed_qty, '0'), '.') . ' ' . ($item->parsed_unit ?: '') : '',
                    $availableSlots,
                );
            })
            ->implode("\n");

        $userPrompt = "## КОНТЕКСТ\n"
            . "Заявка: {$request->internal_code}" . ($request->subject ? ' («' . trim((string) $request->subject) . '»)' : '') . "\n"
            . "Клиент: " . ($request->client_name ?: $request->client_email) . "\n"
            . "Batch вопросов отправлен: " . ($batch->sent_at?->toIso8601String() ?? '—') . "\n"
            . "\n"
            . "## ОТПРАВЛЕННЫЕ ВОПРОСЫ\n"
            . $questionsBlock . "\n"
            . ($batch->general_question ? "\n  general_question_text: \"" . str_replace('"', '\\"', (string) $batch->general_question) . "\"\n" : '')
            . "\n"
            . "## ТЕКУЩИЕ ПОЗИЦИИ ЗАЯВКИ (для enrichment_suggestions)\n"
            . $itemsBlock . "\n"
            . "\n"
            . "## ОТВЕТ КЛИЕНТА\n"
            . "From: " . ($inbound->from_name ?: '') . " <" . ($inbound->from_email ?: '') . ">\n"
            . "Date: " . ($inbound->sent_at?->toIso8601String() ?? '') . "\n"
            . "Subject: " . ($inbound->subject ?? '') . "\n"
            . "\n"
            . ($body !== '' ? $body : '(пустое тело письма)');

        return [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
Ты — анализатор клиентских ответов на уточняющие вопросы менеджера CRM
MyZip (запчасти лифтового оборудования). На вход — список заданных
клиенту вопросов + текущие позиции заявки + текст ответа клиента.

Задачи:

1) ANSWER MATCHING. Для каждого вопроса определи, ответил ли клиент
   и что именно. Скопируй ответ ИЗ ТЕКСТА КЛИЕНТА (точная цитата или
   близкая перефразировка). Если клиент НЕ ответил на конкретный вопрос
   — answer=null.

2) ENRICHMENT EXTRACTION. Если клиент в ответе прислал данные, которыми
   можно ОБОГАТИТЬ позицию (артикул, бренд, количество, марка лифта,
   серия, размеры, мощность и т.п.), выдели их.
   Каждый suggestion указывает item_id, field, value, source_quote.

   FIELD может быть:
   • parsed_article — каталожный артикул позиции;
   • parsed_brand — бренд САМОЙ ПОЗИЦИИ (Telemecanique, Schneider, ABB);
   • parsed_qty — количество;
   • kb:<slug> — KB-параметр КАТЕГОРИИ из available_slots позиции
     (например kb:lift_brand — марка лифта, kb:lift_series — серия лифта,
     kb:door_width — ширина дверного проёма).

   ВАЖНОЕ ОТЛИЧИЕ:
   • parsed_brand = БРЕНД-ПРОИЗВОДИТЕЛЬ ПОЗИЦИИ (марка реле, контактора).
   • kb:lift_brand = МАРКА ЛИФТА для которого нужна позиция (КМЗ, Otis,
     Mosotis), это контекст применения, НЕ бренд самой позиции.

═══ ПРАВИЛА ANSWER MATCHING ═══

• Одна цитата может покрывать несколько вопросов — раскладывай.
• Если клиент написал «не знаю», «уточню позже», «у меня нет такой инфы»
  — это валидный ответ, копируй как есть, не null.
• Если клиент проигнорировал вопрос (нет упоминания темы) — answer=null.
• Не выдумывай. Только то, что физически есть в тексте.
• Цитата короткая — 1-3 предложения максимум.
• cited_phrase — точная подстрока ответа.

═══ ПРАВИЛА ENRICHMENT EXTRACTION ═══

• Только если клиент явно сообщил данные про КОНКРЕТНУЮ позицию.
• item_id обязательно — выбирай из списка позиций.

• ПРИОРИТЕТ field selection:
  1. Если у вопроса есть target_slot_key и клиент ответил на него —
     suggestion должен иметь field=<target_slot_key>. НЕ угадывай другое
     поле, доверяй target.
  2. Иначе выбирай field из available_slots позиции на основе смысла
     ответа.
  3. Если ни target_slot_key ни подходящий available_slot — игнорируй.

• value — нормализуй слегка (trim, без лишних пробелов).
• Не создавай suggestion если позиция уже имеет это значение
  (already=X в available_slots → клиент пишет то же → не предлагай).
• source_quote — точная подстрока ответа.
• confidence: 0.0-1.0. Если target_slot_key совпал — 0.9+. Без target —
  0.6-0.85 (по уверенности).

═══ ПРИМЕРЫ ═══

ПРИМЕР 1 (target_slot_key направляет в правильное поле):

Вопрос: question_id=42, target_slot_key="kb:lift_brand",
        question="Уточните марку лифта для позиции #2"
Available_slots позиции #2: brand ("Бренд", already=Schneider);
                            kb:lift_brand ("Марка лифта", required);
                            kb:lift_series ("Серия лифта")
Клиент: «марка КМЗ»

Ответ:
{
  "question_answers": [
    {"question_id": 42, "answer": "марка КМЗ", "cited_phrase": "марка КМЗ"}
  ],
  "enrichment_suggestions": [
    {"item_id": <id_позиции>, "field": "kb:lift_brand", "value": "КМЗ",
     "source_quote": "марка КМЗ", "confidence": 0.95}
  ]
}

(field=kb:lift_brand — потому что target_slot_key вопроса указывает туда.
parsed_brand=Schneider уже корректен — клиент имел в виду марку ЛИФТА.)

ПРИМЕР 2 (без target — выбираем по смыслу):

Вопрос: question_id=43, target_slot_key=null,
        question="По #2 контактор: уточните количество"
Клиент: «5 штук, артикул A013250»

Ответ:
{
  "enrichment_suggestions": [
    {"item_id": <id>, "field": "parsed_qty", "value": "5",
     "source_quote": "5 штук", "confidence": 0.9},
    {"item_id": <id>, "field": "parsed_article", "value": "A013250",
     "source_quote": "артикул A013250", "confidence": 0.92}
  ]
}

═══ ФОРМАТ ОТВЕТА ═══

Строго JSON без markdown:

{
  "question_answers": [
    {
      "question_id": int,
      "answer": "string | null",
      "cited_phrase": "string | null"
    }
  ],
  "enrichment_suggestions": [
    {
      "item_id": int,
      "field": "parsed_article | parsed_brand | parsed_qty | kb:<slug>",
      "value": "string",
      "source_quote": "string",
      "confidence": 0.0-1.0
    }
  ]
}

Если клиент вообще не ответил по теме (одно «спасибо» или out-of-office),
question_answers все с answer=null, enrichment_suggestions=[].
PROMPT;
    }
}
