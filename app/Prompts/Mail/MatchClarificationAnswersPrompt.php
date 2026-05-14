<?php

namespace App\Prompts\Mail;

use App\Models\ClarificationBatch;
use App\Models\EmailMessage;
use App\Models\Request as RequestModel;

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

        // Список вопросов с привязкой к позициям, в формате:
        //   - question_id: ...
        //     scope: position N "<name>" / general
        //     question: "..."
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
                '- question_id: %d
  scope: %s
  question: "%s"',
                $q->id,
                $scope,
                str_replace('"', '\\"', (string) $q->question),
            );
        })->implode("\n");

        // Список всех активных позиций заявки — нужно для enrichment_suggestions
        // чтобы LLM мог указать item_id даже если вопроса по этой позиции нет
        // (клиент мог дополнительно прислать инфу).
        $itemsBlock = $request->items
            ->where('is_active', true)
            ->map(fn ($item) => sprintf(
                '- item_id: %d, position #%d, name: "%s"%s%s%s',
                $item->id,
                $item->position,
                str_replace('"', '\\"', (string) ($item->parsed_name ?? '')),
                $item->parsed_brand ? ', brand: "' . $item->parsed_brand . '"' : '',
                $item->parsed_article ? ', article: "' . $item->parsed_article . '"' : '',
                $item->parsed_qty ? ', qty: ' . rtrim(rtrim((string) $item->parsed_qty, '0'), '.') . ' ' . ($item->parsed_unit ?: '') : '',
            ))
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
   можно ОБОГАТИТЬ позицию (артикул, бренд, количество, цвет, мощность,
   длина и т.п.), выдели их. Каждый suggestion указывает item_id (из
   списка позиций), field (одно из: parsed_article, parsed_brand,
   parsed_qty), value, source_quote (цитата из ответа клиента).

═══ ПРАВИЛА ANSWER MATCHING ═══

• Одна цитата может покрывать несколько вопросов — раскладывай.
• Если клиент написал «не знаю», «уточню позже», «у меня нет такой инфы»
  — это валидный ответ, копируй как есть, не null.
• Если клиент проигнорировал вопрос (нет упоминания темы) — answer=null.
• Не выдумывай. Только то, что физически есть в тексте.
• Цитата короткая — 1-3 предложения максимум.
• cited_phrase — точная подстрока ответа (для UI «увидеть в контексте»).

═══ ПРАВИЛА ENRICHMENT EXTRACTION ═══

• Только если клиент явно сообщил данные про КОНКРЕТНУЮ позицию.
• item_id обязательно — выбирай из списка позиций.
• field: parsed_article / parsed_brand / parsed_qty (другие — игнорируй).
• value — нормализуй слегка (trim, без лишних пробелов).
• Не создавай suggestion если позиция уже имеет это значение
  (например parsed_article="M02016" уже стоит, клиент пишет
  «арт. M02016» — не предлагай).
• source_quote — точная подстрока ответа клиента.
• confidence: для каждого suggestion — 0.0-1.0, отражает уверенность
  что клиент сообщил именно про эту позицию (mostly 0.7-0.9 для
  явных кейсов).

═══ ПРИМЕРЫ ═══

Запросили: «По позиции #1 «Кнопка KONE»: пришлите фото шильдика»,
            «По позиции #2 «Контактор»: уточните количество»
Клиент: «Фото отправлю позже. Контакторов нужно 5 штук, артикул A013250»

Ответ:
{
  "question_answers": [
    {"question_id": <id1>, "answer": "Фото отправлю позже.", "cited_phrase": "Фото отправлю позже."},
    {"question_id": <id2>, "answer": "5 штук, артикул A013250", "cited_phrase": "Контакторов нужно 5 штук, артикул A013250"}
  ],
  "enrichment_suggestions": [
    {"item_id": <id_контактора>, "field": "parsed_qty", "value": "5", "source_quote": "Контакторов нужно 5 штук", "confidence": 0.9},
    {"item_id": <id_контактора>, "field": "parsed_article", "value": "A013250", "source_quote": "артикул A013250", "confidence": 0.92}
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
      "field": "parsed_article | parsed_brand | parsed_qty",
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
