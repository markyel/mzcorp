<?php

namespace App\Services\Request;

use App\Models\EmailMessage;
use App\Models\Request;
use App\Prompts\Request\AutoCloseDecisionPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Финальный LLM-чек перед автозакрытием unassigned Pending-заявки без позиций.
 *
 * Используется из `RequestsRecoverUnassignedCommand` (hourly cron). До этого
 * сервиса cron безусловно закрывал «пустые» заявки старше threshold через
 * `RequestStateService::systemCloseLost(ParserNoContent)`. Это рождало
 * ложно-закрытые случаи: парсер не справился (Vision промахнулся, скан,
 * нестандартный формат), а заявка реально была.
 *
 * Сервис вызывает gpt-4o-mini с письмом-источником и просит решить:
 *   - close: явная «пустышка» — безопасно автозакрыть.
 *   - keep: похоже на запрос — отдать менеджеру.
 *
 * **Принцип сомнения**: при confidence ниже порога считаем `keep` (false-
 * positive менеджеру дешевле потерянной заявки).
 *
 * Fail-safe: если LLM падает / парсинг неудачен — возвращаем `keep` с
 * confidence=0 и reasoning об ошибке. Caller (cron) тогда выполняет
 * autoAssign вместо close, как страховку.
 */
class AutoCloseDecisionService
{
    private const KEEP_FLOOR = 0.6;

    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly AutoCloseDecisionPrompt $prompt,
    ) {
    }

    /**
     * @return array{verdict: 'close'|'keep', confidence: float, reasoning: string}
     */
    public function decide(Request $request, EmailMessage $email): array
    {
        $messages = $this->prompt->build($request, $email);

        try {
            $result = $this->openai->chat(
                $messages,
                config('services.openai.auto_close_model', 'gpt-4o-mini'),
                [
                    'temperature' => 0,
                    'max_tokens' => 400,
                    'response_format' => ['type' => 'json_object'],
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('AutoCloseDecisionService: LLM call failed → keep (safe fallback)', [
                'request_id' => $request->id,
                'email_message_id' => $email->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'verdict' => 'keep',
                'confidence' => 0.0,
                'reasoning' => 'LLM unavailable: ' . $e->getMessage(),
            ];
        }

        $parsed = json_decode($result['content'] ?? '', true);
        if (! is_array($parsed) || ! isset($parsed['verdict'])) {
            Log::warning('AutoCloseDecisionService: invalid LLM response → keep', [
                'request_id' => $request->id,
                'email_message_id' => $email->id,
                'raw' => mb_substr((string) ($result['content'] ?? ''), 0, 400),
            ]);

            return [
                'verdict' => 'keep',
                'confidence' => 0.0,
                'reasoning' => 'LLM вернул невалидный JSON',
            ];
        }

        $verdict = $parsed['verdict'] === 'close' ? 'close' : 'keep';
        $confidence = max(0.0, min(1.0, (float) ($parsed['confidence'] ?? 0)));
        $reasoning = trim((string) ($parsed['reasoning'] ?? ''));
        if ($reasoning === '') {
            $reasoning = '(LLM не дал reasoning)';
        }

        // Принцип сомнения: если LLM ХОЧЕТ закрыть, но не уверен —
        // флипаем в keep. Лучше менеджеру 30 секунд на «не заявка», чем
        // потерять реальный запрос.
        if ($verdict === 'close' && $confidence < self::KEEP_FLOOR) {
            return [
                'verdict' => 'keep',
                'confidence' => $confidence,
                'reasoning' => '(low-confidence close → flipped to keep) ' . $reasoning,
            ];
        }

        return [
            'verdict' => $verdict,
            'confidence' => $confidence,
            'reasoning' => $reasoning,
        ];
    }
}
