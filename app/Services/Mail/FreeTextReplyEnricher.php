<?php

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\Request as RequestModel;
use App\Models\RequestItem;
use App\Prompts\Mail\EnrichExistingItemsFromReplyPrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\Catalog\RequestItemEditor;
use Illuminate\Support\Facades\Log;

/**
 * Path C (2026-05-21): обработка free-text reply-сообщения от клиента,
 * которое не содержит структурированных позиций, но может содержать
 * уточнения по уже существующим (например, «масленка на противовесе»,
 * «по плате — это ARO 47.RDP»).
 *
 * Триггер: ParseRequestItemsJob получил пустой items[] для reply
 * (message.related_request_id ≠ null), нет активного ClarificationBatch
 * (тот случай уже покрыт ClarificationAnswerMatcher).
 *
 * Принцип: переиспользуем существующий канал enrichment_suggestions в
 * request_items.quality_assessment_payload — UI (💡 badge и блок
 * «Предложенные уточнения» в detail.blade.php) автоматически подхватит.
 * Дополнительный сервис, не дополнительный UI.
 */
class FreeTextReplyEnricher
{
    private const LLM_MODEL = 'gpt-4o';
    private const LLM_TEMPERATURE = 0.2;
    private const LLM_MAX_TOKENS = 1500;
    private const CONFIDENCE_FLOOR = 0.6;
    private const ENRICHABLE_FIELDS = ['parsed_article', 'parsed_brand', 'parsed_qty', 'note'];

    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly RequestItemEditor $editor,
    ) {
    }

    /**
     * @return array{suggestions: int, auto_applied: int}
     */
    public function enrich(EmailMessage $inbound, RequestModel $request): array
    {
        $body = trim((string) ($inbound->body_plain ?? ''));
        if ($body === '' && ! empty($inbound->body_html)) {
            $body = trim(strip_tags((string) $inbound->body_html));
        }
        if (mb_strlen($body) < 5) {
            return ['suggestions' => 0, 'auto_applied' => 0];
        }

        $items = $request->items()->where('is_active', true)->orderBy('position')->get();
        if ($items->isEmpty()) {
            return ['suggestions' => 0, 'auto_applied' => 0];
        }

        $itemsBrief = $items->map(fn (RequestItem $i) => [
            'id' => $i->id,
            'position' => $i->position,
            'parsed_name' => $i->parsed_name,
            'parsed_brand' => $i->parsed_brand,
            'parsed_article' => $i->parsed_article,
            'parsed_qty' => $i->parsed_qty,
            'parsed_unit' => $i->parsed_unit,
        ])->all();

        try {
            $messages = EnrichExistingItemsFromReplyPrompt::build($itemsBrief, $body, $inbound->subject);
            $response = $this->openai->chat($messages, self::LLM_MODEL, [
                'response_format' => ['type' => 'json_object'],
                'temperature' => self::LLM_TEMPERATURE,
                'max_tokens' => self::LLM_MAX_TOKENS,
            ]);
        } catch (\Throwable $e) {
            Log::warning('FreeTextReplyEnricher: LLM call failed', [
                'request_id' => $request->id,
                'inbound_id' => $inbound->id,
                'error' => $e->getMessage(),
            ]);
            return ['suggestions' => 0, 'auto_applied' => 0];
        }

        $raw = (string) ($response['content'] ?? '');
        $parsed = json_decode($raw, true);
        if (! is_array($parsed)) {
            Log::warning('FreeTextReplyEnricher: invalid JSON', [
                'request_id' => $request->id,
                'inbound_id' => $inbound->id,
                'raw_excerpt' => mb_substr($raw, 0, 300),
            ]);
            return ['suggestions' => 0, 'auto_applied' => 0];
        }

        $suggestions = is_array($parsed['suggestions'] ?? null) ? $parsed['suggestions'] : [];
        if (empty($suggestions)) {
            return ['suggestions' => 0, 'auto_applied' => 0];
        }

        $itemsById = $items->keyBy('id');
        $stored = 0;
        $autoApplied = 0;
        $autoApplyThreshold = (float) config('services.clarifications.auto_apply_threshold', 0.95);

        foreach ($suggestions as $s) {
            if (! is_array($s)) {
                continue;
            }
            $itemId = (int) ($s['item_id'] ?? 0);
            $field = (string) ($s['field'] ?? '');
            $value = trim((string) ($s['value'] ?? ''));
            $confidence = (float) ($s['confidence'] ?? 0);
            $sourceQuote = trim((string) ($s['source_quote'] ?? ''));
            $reasoning = trim((string) ($s['reasoning'] ?? ''));

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
            $item = $itemsById->get($itemId);
            if ($item === null || $item->request_id !== $request->id) {
                continue;
            }
            if ($this->valueAlreadyPresent($item, $field, $value)) {
                continue;
            }

            $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
            $existing = is_array($payload['enrichment_suggestions'] ?? null) ? $payload['enrichment_suggestions'] : [];

            // Дедуп: то же field+value уже предложено и не закрыто.
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
                'reasoning' => mb_substr($reasoning, 0, 300),
                'suggested_at' => now()->toIso8601String(),
                'source_message_id' => $inbound->id,
                'source_batch_id' => null,
                'source_origin' => 'free_text_reply', // отличает от Path B (batch)
                'status' => 'pending',
            ];

            $payload['enrichment_suggestions'] = $existing;
            $item->quality_assessment_payload = $payload;
            $item->save();
            $stored++;

            // Auto-apply при высоком confidence — повторяет логику
            // ClarificationAnswerMatcher Phase E.3, но без привязки к batch.
            if ($autoApplyThreshold > 0 && $confidence >= $autoApplyThreshold) {
                $author = $request->assignedUser;
                if ($author === null) {
                    continue;
                }
                try {
                    $this->editor->applyEnrichmentSuggestion($item->fresh(), $suggId, $author);

                    // Помечаем auto_applied=true для UI.
                    $fresh = $item->fresh();
                    $p = is_array($fresh->quality_assessment_payload) ? $fresh->quality_assessment_payload : [];
                    $sugs = is_array($p['enrichment_suggestions'] ?? null) ? $p['enrichment_suggestions'] : [];
                    foreach ($sugs as $i => $sug) {
                        if (is_array($sug) && ($sug['id'] ?? null) === $suggId
                            && ($sug['status'] ?? '') === 'applied') {
                            $sugs[$i]['auto_applied'] = true;
                        }
                    }
                    $p['enrichment_suggestions'] = $sugs;
                    $fresh->quality_assessment_payload = $p;
                    $fresh->save();

                    $autoApplied++;
                    Log::info('FreeTextReplyEnricher: auto-applied', [
                        'request_id' => $request->id,
                        'item_id' => $item->id,
                        'suggestion_id' => $suggId,
                        'field' => $field,
                        'confidence' => $confidence,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('FreeTextReplyEnricher: auto-apply failed, оставляем pending', [
                        'item_id' => $item->id,
                        'suggestion_id' => $suggId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('FreeTextReplyEnricher: done', [
            'request_id' => $request->id,
            'inbound_id' => $inbound->id,
            'stored' => $stored,
            'auto_applied' => $autoApplied,
        ]);

        return ['suggestions' => $stored, 'auto_applied' => $autoApplied];
    }

    private function valueAlreadyPresent(RequestItem $item, string $field, string $value): bool
    {
        $norm = $this->normalizeFieldValue($value);
        if ($field === 'parsed_brand') {
            return $this->normalizeFieldValue((string) ($item->parsed_brand ?? '')) === $norm;
        }
        if ($field === 'parsed_article') {
            // Учитываем варианты через запятую.
            $existing = (string) ($item->parsed_article ?? '');
            foreach (preg_split('/\s*,\s*/', $existing) as $token) {
                if ($this->normalizeFieldValue($token) === $norm) {
                    return true;
                }
            }
            return false;
        }
        if ($field === 'parsed_qty') {
            return $this->normalizeFieldValue((string) ($item->parsed_qty ?? '')) === $norm;
        }
        // note / kb:<slug> — duplication через payload dedup, не через item-поля.
        return false;
    }

    private function normalizeFieldValue(string $v): string
    {
        return mb_strtolower(preg_replace('/[\s\-_.\/]/u', '', trim($v)) ?? '');
    }
}
