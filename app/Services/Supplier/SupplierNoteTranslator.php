<?php

namespace App\Services\Supplier;

use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Примечание к запросу поставщикам (поле «Примечание» в «Снабжении» и в рассылке из заявки)
 * на языке письма: поставщику с языком общения English — по-английски, как названия позиций.
 * Перевод один на рассылку (запоминается по тексту), артикулы/размеры/бренды — как есть.
 * Fail-safe: модель не ответила — уходит как написано.
 */
class SupplierNoteTranslator
{
    /** @var array<string, string> «язык|текст» => перевод */
    private array $memo = [];

    public function __construct(private readonly OpenAIChatService $openai) {}

    public function forLanguage(?string $note, string $lang): string
    {
        $note = trim((string) $note);
        // Русскоязычному — как написано; английский текст переводить незачем.
        if ($note === '' || $lang !== 'en' || ! preg_match('/\p{Cyrillic}/u', $note)) {
            return $note;
        }

        return $this->memo[$lang.'|'.$note] ??= $this->toEnglish($note) ?? $note;
    }

    private function toEnglish(string $text): ?string
    {
        $system = 'You translate a note from a buyer of elevator and escalator spare parts, attached to a price request to a foreign supplier, from Russian to English. '
            .'Keep brand names, model codes, part numbers, dimensions, quantities and units unchanged. '
            .'Write natural, polite, concise business English. Do not add or drop information. Return only the translated text.';

        try {
            $result = $this->openai->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $text],
            ], config('services.openai.intent_model', 'gpt-4o-mini'), ['temperature' => 0, 'max_tokens' => 1500]);
            $out = trim((string) ($result['content'] ?? ''));

            return $out !== '' && ! preg_match('/\p{Cyrillic}{4,}/u', $out) ? $out : null;
        } catch (Throwable $e) {
            Log::warning('SupplierNoteTranslator: LLM call failed (non-fatal)', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
