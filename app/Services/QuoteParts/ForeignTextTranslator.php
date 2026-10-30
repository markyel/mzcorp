<?php

namespace App\Services\QuoteParts;

use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Перевод на русский того, что поставщик QuoteParts написал по-китайски (японски, корейски):
 * примечание к предложению, бренд и артикул аналога, письма в переписке. Снабжение читает
 * только по-русски и по-английски — английский не трогаем.
 *
 * Батчем, с кэшем по самому тексту: синхронизация повторяет те же предложения, платим
 * за перевод один раз. Fail-safe: не перевелось — текст остаётся как был, повтор при
 * следующей синхронизации.
 */
class ForeignTextTranslator
{
    private const CACHE_PREFIX = 'qp:ru:';

    public function __construct(private readonly OpenAIChatService $openai) {}

    /** Есть иероглифы, кана или хангыль — без перевода не прочитать. */
    public static function needs(?string $text): bool
    {
        return $text !== null && preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text) === 1;
    }

    /**
     * @param  list<string>  $texts
     * @return array<string, string> исходный текст => перевод (только те, что нуждались и перевелись;
     *                               «нет» / «не указано» переводится в пустую строку)
     */
    public function toRussian(array $texts): array
    {
        $out = [];
        $todo = [];
        foreach (array_unique(array_filter($texts, fn ($t) => self::needs($t))) as $text) {
            $cached = Cache::get(self::CACHE_PREFIX.sha1($text));
            if (is_string($cached)) {
                $out[$text] = $cached;
            } else {
                $todo[] = $text;
            }
        }

        foreach (array_chunk($todo, 40) as $chunk) {
            foreach ($this->ask($chunk) as $text => $ru) {
                Cache::forever(self::CACHE_PREFIX.sha1($text), $ru);
                $out[$text] = $ru;
            }
        }

        return $out;
    }

    /** Один текст; null — переводить нечего или не вышло. */
    public function one(?string $text): ?string
    {
        return $text === null ? null : ($this->toRussian([$text])[$text] ?? null);
    }

    /**
     * @param  list<string>  $texts
     * @return array<string, string>
     */
    private function ask(array $texts): array
    {
        $system = 'You translate messages from suppliers of elevator and escalator spare parts into Russian for a purchasing team. '
            .'The input is JSON {"texts":{"<id>":"<text>"}}; the texts are notes to a price offer, a brand or part number of an analog, '
            .'or an e-mail. Translate each faithfully and completely into Russian — no Chinese, Japanese or Korean words may remain. '
            .'Keep brand names, model codes, part numbers, dimensions, quantities and units exactly as written. '
            .'Use the elevator trade terms a Russian buyer would use. If a text only means "none / no / not applicable" '
            .'(e.g. 无, 没有, 暂无), return an empty string for it. Keep line breaks. '
            .'Return STRICT JSON {"texts":{"<id>":"<russian>"}} with the same ids.';

        $payload = [];
        foreach (array_values($texts) as $i => $t) {
            $payload[(string) $i] = $t;
        }

        try {
            $result = $this->openai->chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => json_encode(['texts' => $payload], JSON_UNESCAPED_UNICODE)],
            ], config('services.openai.item_translation_model', 'gpt-4.1'), [
                'temperature' => 0,
                'max_tokens' => 8000,
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (Throwable $e) {
            Log::warning('ForeignTextTranslator: LLM call failed (non-fatal)', ['count' => count($texts), 'error' => $e->getMessage()]);

            return [];
        }

        $parsed = json_decode((string) ($result['content'] ?? ''), true);
        $out = [];
        foreach ((array) ($parsed['texts'] ?? []) as $id => $ru) {
            $source = $payload[(string) $id] ?? null;
            // Перевод, где остались иероглифы, — не перевод: оставим оригинал до следующей попытки.
            if ($source !== null && is_string($ru) && ! self::needs($ru)) {
                $out[$source] = trim($ru);
            }
        }

        return $out;
    }
}
