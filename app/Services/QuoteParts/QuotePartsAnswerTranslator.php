<?php

namespace App\Services\QuoteParts;

use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Перевод ответа менеджера поставщику RU→EN (поставщик с языком общения English), как в
 * запросе поставщикам из «Снабжения»: менеджер пишет по-русски, перевод показывается и
 * правится перед отправкой. Артикулы, размеры, бренды, числа — без изменений.
 * Fail-safe: при ошибке модели — null (менеджер переводит сам).
 */
class QuotePartsAnswerTranslator
{
    public function __construct(private readonly OpenAIChatService $openai) {}

    public function toEnglish(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $system = 'You translate a message from a buyer of elevator and escalator spare parts to a foreign supplier, from Russian to English. '
            .'It answers the supplier\'s questions about a price request. Keep brand names, model codes, part numbers, dimensions, quantities and units unchanged. '
            .'Write natural, polite, concise business English. Do not add or drop information. Return only the translated text.';

        try {
            $result = $this->openai->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $text],
            ], config('services.openai.intent_model', 'gpt-4o-mini'), ['temperature' => 0, 'max_tokens' => 1500]);

            $out = trim((string) ($result['content'] ?? ''));

            return $out !== '' ? $out : null;
        } catch (Throwable $e) {
            Log::warning('QuotePartsAnswerTranslator: LLM call failed (non-fatal)', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Ответ из богатого редактора (ответ поддержки QuoteParts): переводим только текст, разметка — таблицы,
     * ссылки, картинки, выделения — остаётся как была. Результат ещё раз пропускает HtmlSanitizer у вызывающего.
     */
    public function toEnglishHtml(string $html): ?string
    {
        $html = trim($html);
        if ($html === '') {
            return null;
        }

        $system = 'You translate a support e-mail of the QuoteParts platform (elevator and escalator spare parts) from Russian to English. '
            .'The input is HTML. Translate only the human-readable text; keep every tag, attribute, link, image and table exactly as it is. '
            .'Keep brand names, model codes, part numbers, dimensions, quantities and units unchanged. '
            .'Write natural, polite, concise business English. Do not add or drop information. Return only the translated HTML.';

        try {
            $result = $this->openai->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $html],
            ], config('services.openai.intent_model', 'gpt-4o-mini'), ['temperature' => 0, 'max_tokens' => 4000]);

            // Модель иногда оборачивает ответ в ```html … ```.
            $out = trim(preg_replace('/^```(?:html)?\s*|\s*```$/i', '', trim((string) ($result['content'] ?? ''))) ?? '');

            return $out !== '' ? $out : null;
        } catch (Throwable $e) {
            Log::warning('QuotePartsAnswerTranslator: LLM call failed (non-fatal)', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
