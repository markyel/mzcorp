<?php

namespace App\Services\Mail;

use App\Models\ClarificationBatch;
use App\Models\ClarificationQuestion;
use App\Models\EmailMessage;
use App\Models\Request as RequestModel;
use App\Models\RequestItem;
use App\Prompts\Mail\MatchClarificationAnswersPrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\Catalog\RequestItemEditor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Foundation §6.2 Phase B/C — сматчинг ответа клиента на вопросы +
 * извлечение enrichment suggestions.
 *
 * Триггер: inbound-письмо привязано к Request, у которой есть
 * ClarificationBatch со status='sent'. Запускается из
 * MatchClarificationAnswersJob (асинхронно).
 *
 * Логика:
 *  1. Идём LLM-промптом MatchClarificationAnswersPrompt.
 *  2. Для каждого question_answer с непустым answer заполняем
 *     clarification_questions.answer + answered_at +
 *     answered_via_message_id.
 *  3. Если ВСЕ вопросы batch'а получили answer — batch.status='answered'
 *     + answered_at=now(). Это переход finite state.
 *  4. enrichment_suggestions сохраняем в
 *     request_items.quality_assessment_payload.enrichment_suggestions[]
 *     (накопительно, deduplicated по {field, value}).
 *
 * Идемпотентность:
 *  - Повторный запуск на том же inbound + batch — answers могут
 *    перезаписаться (свежие данные), но answered_via_message_id
 *    останется на оригинальный inbound (если уже стоит).
 *  - Suggestions deduplicated по уникальной комбинации
 *    (field, normalized value) — повторно не плодим.
 */
class ClarificationAnswerMatcher
{
    private const CONFIDENCE_FLOOR = 0.6;

    /** Только эти поля можно предлагать к обогащению. */
    private const ENRICHABLE_FIELDS = ['parsed_article', 'parsed_brand', 'parsed_qty'];

    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly MatchClarificationAnswersPrompt $prompt,
        private readonly RequestItemEditor $editor,
    ) {
    }

    /**
     * @return array{
     *   answered: int,
     *   suggestions: int,
     *   batch_completed: bool
     * }
     */
    public function match(EmailMessage $inbound, ClarificationBatch $batch): array
    {
        $batch->loadMissing(['request.items', 'questions.requestItem']);
        $request = $batch->request;
        if ($request === null) {
            return ['answered' => 0, 'suggestions' => 0, 'batch_completed' => false];
        }

        $messages = $this->prompt->build($inbound, $batch, $request);

        try {
            $result = $this->openai->chat(
                $messages,
                config('services.openai.intent_model', 'gpt-4o-mini'),
                [
                    'temperature' => 0,
                    'max_tokens' => 2000,
                    'response_format' => ['type' => 'json_object'],
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('ClarificationAnswerMatcher: LLM call failed (non-fatal)', [
                'batch_id' => $batch->id,
                'email_message_id' => $inbound->id,
                'error' => $e->getMessage(),
            ]);

            return ['answered' => 0, 'suggestions' => 0, 'batch_completed' => false];
        }

        $parsed = json_decode($result['content'] ?? '', true);
        if (! is_array($parsed)) {
            Log::warning('ClarificationAnswerMatcher: invalid LLM response', [
                'batch_id' => $batch->id,
                'raw' => mb_substr((string) ($result['content'] ?? ''), 0, 400),
            ]);

            return ['answered' => 0, 'suggestions' => 0, 'batch_completed' => false];
        }

        $answered = 0;
        $suggestionsCount = 0;
        $batchCompleted = false;

        DB::transaction(function () use ($parsed, $batch, $inbound, $request, &$answered, &$suggestionsCount, &$batchCompleted) {
            // 1. Заполняем answers.
            $questionAnswers = is_array($parsed['question_answers'] ?? null) ? $parsed['question_answers'] : [];
            $batchQuestionIds = $batch->questions->pluck('id')->all();

            foreach ($questionAnswers as $qa) {
                if (! is_array($qa)) {
                    continue;
                }
                $qid = (int) ($qa['question_id'] ?? 0);
                $answer = trim((string) ($qa['answer'] ?? ''));
                // LLM иногда возвращает строку "null" / "—" / "n/a" вместо
                // настоящего JSON-null. Фильтруем сразу — это «нет ответа».
                if (in_array(mb_strtolower($answer), ['null', 'none', '—', '-', 'n/a'], true)) {
                    $answer = '';
                }
                if ($qid === 0 || $answer === '' || ! in_array($qid, $batchQuestionIds, true)) {
                    continue;
                }
                /** @var ClarificationQuestion|null $q */
                $q = ClarificationQuestion::find($qid);
                if ($q === null || $q->batch_id !== $batch->id) {
                    continue;
                }
                // Не перезаписываем уже existing answer (первый ответ важнее).
                if (trim((string) $q->answer) !== '') {
                    continue;
                }
                $q->update([
                    'answer' => mb_substr($answer, 0, 2000),
                    'answered_at' => now(),
                    'answered_via_message_id' => $inbound->id,
                ]);
                $answered++;
            }

            // 2. Batch completion check — все вопросы получили answer?
            $totalQ = $batch->questions()->count();
            $answeredQ = $batch->questions()->whereNotNull('answered_at')->count();
            if ($totalQ > 0 && $answeredQ === $totalQ && $batch->answered_at === null) {
                $batch->update([
                    'status' => ClarificationBatch::STATUS_ANSWERED,
                    'answered_at' => now(),
                ]);
                $batchCompleted = true;
            }

            // 3. Enrichment suggestions — пишем в request_items.quality_assessment_payload.
            $suggestions = is_array($parsed['enrichment_suggestions'] ?? null) ? $parsed['enrichment_suggestions'] : [];
            $itemsByid = $request->items->keyBy('id');

            foreach ($suggestions as $s) {
                if (! is_array($s)) {
                    continue;
                }
                $itemId = (int) ($s['item_id'] ?? 0);
                $field = (string) ($s['field'] ?? '');
                $value = trim((string) ($s['value'] ?? ''));
                $confidence = (float) ($s['confidence'] ?? 0);
                $sourceQuote = trim((string) ($s['source_quote'] ?? ''));

                // Phase D: field может быть базовым (parsed_*) или kb:<slug>.
                $isKbField = str_starts_with($field, 'kb:');
                $isBaseField = in_array($field, self::ENRICHABLE_FIELDS, true);
                if ($itemId === 0
                    || (! $isBaseField && ! $isKbField)
                    || $value === ''
                    || $confidence < self::CONFIDENCE_FLOOR
                ) {
                    continue;
                }
                /** @var RequestItem|null $item */
                $item = $itemsByid->get($itemId);
                if ($item === null || $item->request_id !== $request->id) {
                    continue;
                }
                // Skip если позиция уже имеет это значение.
                if ($this->valueAlreadyPresent($item, $field, $value)) {
                    continue;
                }

                $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
                $existing = is_array($payload['enrichment_suggestions'] ?? null) ? $payload['enrichment_suggestions'] : [];

                // Дедуп: тот же field+value уже в списке (не applied/dismissed).
                $duplicate = collect($existing)->contains(function ($e) use ($field, $value) {
                    return is_array($e)
                        && ($e['field'] ?? null) === $field
                        && $this->normalizeFieldValue((string) ($e['value'] ?? '')) === $this->normalizeFieldValue($value)
                        && ($e['status'] ?? 'pending') === 'pending';
                });
                if ($duplicate) {
                    continue;
                }

                $suggId = bin2hex(random_bytes(4));
                $existing[] = [
                    'id' => $suggId,
                    'field' => $field,
                    'value' => $value,
                    'source_quote' => mb_substr($sourceQuote, 0, 500),
                    'confidence' => round($confidence, 2),
                    'suggested_at' => now()->toIso8601String(),
                    'source_message_id' => $inbound->id,
                    'source_batch_id' => $batch->id,
                    'status' => 'pending', // pending | applied | dismissed | reverted
                ];

                $payload['enrichment_suggestions'] = $existing;
                $item->quality_assessment_payload = $payload;
                $item->save();
                $suggestionsCount++;

                // Phase E.3 — auto-apply при высокой уверенности.
                $autoApplyThreshold = (float) config('services.clarifications.auto_apply_threshold', 0.95);
                $requireTarget = (bool) config('services.clarifications.auto_apply_require_target', true);
                $hasTargetSlotMatch = $this->suggestionMatchesQuestionTarget($batch, $field);
                $shouldAutoApply = $autoApplyThreshold > 0
                    && $confidence >= $autoApplyThreshold
                    && (! $requireTarget || $hasTargetSlotMatch);

                if ($shouldAutoApply) {
                    try {
                        // Применяем от имени менеджера, создавшего batch.
                        $author = $batch->createdBy;
                        if ($author === null) {
                            // Fallback: первый assignment'ный manager. Если и тут
                            // нет — пропускаем auto-apply (применит вручную).
                            $author = $request->assignedUser;
                        }
                        if ($author !== null) {
                            $this->editor->applyEnrichmentSuggestion($item->fresh(), $suggId, $author);

                            // Помечаем как auto_applied для UI («✓ применено авто»).
                            $fresh = $item->fresh();
                            $p = is_array($fresh->quality_assessment_payload) ? $fresh->quality_assessment_payload : [];
                            $sugs = is_array($p['enrichment_suggestions'] ?? null) ? $p['enrichment_suggestions'] : [];
                            foreach ($sugs as $i => $s) {
                                if (is_array($s) && ($s['id'] ?? null) === $suggId
                                    && ($s['status'] ?? '') === 'applied') {
                                    $sugs[$i]['auto_applied'] = true;
                                }
                            }
                            $p['enrichment_suggestions'] = $sugs;
                            $fresh->quality_assessment_payload = $p;
                            $fresh->save();

                            Log::info('ClarificationAnswerMatcher: auto-applied', [
                                'item_id' => $item->id,
                                'suggestion_id' => $suggId,
                                'field' => $field,
                                'confidence' => $confidence,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('ClarificationAnswerMatcher: auto-apply failed, оставляем pending', [
                            'item_id' => $item->id,
                            'suggestion_id' => $suggId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        });

        Log::info('ClarificationAnswerMatcher: done', [
            'batch_id' => $batch->id,
            'email_message_id' => $inbound->id,
            'answered' => $answered,
            'suggestions' => $suggestionsCount,
            'batch_completed' => $batchCompleted,
        ]);

        return [
            'answered' => $answered,
            'suggestions' => $suggestionsCount,
            'batch_completed' => $batchCompleted,
        ];
    }

    /**
     * Проверка: значение совпадает с тем что уже стоит у позиции.
     */
    private function valueAlreadyPresent(RequestItem $item, string $field, string $value): bool
    {
        // Phase D: kb:<slug> → проверяем в extracted_parameters[slug].
        if (str_starts_with($field, 'kb:')) {
            $slug = substr($field, 3);
            $p = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
            $extracted = is_array($p['extracted_parameters'] ?? null) ? $p['extracted_parameters'] : [];
            $current = (string) ($extracted[$slug] ?? '');
        } else {
            $current = (string) ($item->{$field} ?? '');
        }
        if ($current === '') {
            return false;
        }

        return $this->normalizeFieldValue($current) === $this->normalizeFieldValue($value);
    }

    private function normalizeFieldValue(string $value): string
    {
        // Удалить все non-alphanum, привести к UC. Подходит для article/brand/qty.
        return mb_strtoupper(preg_replace('/[\s\-_.,\/]/', '', trim($value)) ?? '');
    }

    /**
     * Phase E.3: проверяем, есть ли в batch'е вопрос с target_slot_key,
     * совпадающим с field у suggestion. Match-ит:
     *   field=parsed_brand   ← target_slot_key='brand'
     *   field=parsed_article ← target_slot_key='article'
     *   field=parsed_qty     ← target_slot_key='qty'
     *   field=kb:<slug>      ← target_slot_key='kb:<slug>'
     *
     * Если совпадает — LLM попал в правильный слот, auto-apply безопасен.
     */
    private function suggestionMatchesQuestionTarget(ClarificationBatch $batch, string $field): bool
    {
        $expectedSlotKey = match (true) {
            $field === 'parsed_brand'   => 'brand',
            $field === 'parsed_article' => 'article',
            $field === 'parsed_qty'     => 'qty',
            str_starts_with($field, 'kb:') => $field,
            default => null,
        };
        if ($expectedSlotKey === null) {
            return false;
        }

        foreach ($batch->questions as $q) {
            if ($q->target_slot_key === $expectedSlotKey) {
                return true;
            }
        }

        return false;
    }
}
