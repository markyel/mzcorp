<?php

namespace App\Services\Supplier;

use App\Prompts\Suppliers\TranslateItemNamesPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Перевод названий позиций RU→EN для письма-запроса иностранному поставщику
 * (Фаза 3.2, язык общения) и черновиков QuoteParts. Батчем, fail-safe: при
 * ошибке/мусоре возвращает пустой массив (вызывающий оставляет прежние значения).
 * Название — термином лифтовой отрасли, не дословно (TranslateItemNamesPrompt);
 * коды моделей, артикулы и размеры — как есть. Модель — services.openai.item_translation_model:
 * gpt-4o-mini писал «Cable guiding sheave (Rim KVS …)» вместо «Traction sheave» (M-2026-20451).
 */
class SupplierItemTranslator
{
    public function __construct(
        private readonly OpenAIChatService $openai,
    ) {
    }

    /**
     * @param  array<int, string>  $items  request_item_id => русское название
     * @return array<int, string>  request_item_id => английское название (только успешно переведённые)
     */
    public function translate(array $items): array
    {
        $items = array_filter(array_map('trim', $items), fn ($v) => $v !== '');
        if ($items === []) {
            return [];
        }

        $payload = [];
        foreach ($items as $id => $name) {
            $payload[] = ['id' => (int) $id, 'name' => $name];
        }

        // Две попытки: ответ модели бывает сбойным (19.11.2026: четыре названия ушли поставщикам по-русски — перевод
        // молча вернул пусто). Вторая — только для того, что не перевелось.
        $out = [];
        for ($attempt = 1; $attempt <= 2 && $payload !== []; $attempt++) {
            $out += $this->ask($payload);
            $payload = array_values(array_filter($payload, fn ($p) => ! isset($out[$p['id']])));
        }
        if ($payload !== []) {
            Log::warning('SupplierItemTranslator: names left untranslated', ['ids' => array_column($payload, 'id')]);
        }

        return $out;
    }

    /**
     * @param  list<array{id: int, name: string}>  $payload
     * @return array<int, string>
     */
    private function ask(array $payload): array
    {
        try {
            $result = $this->openai->chat(
                (new TranslateItemNamesPrompt())->build($payload),
                config('services.openai.item_translation_model', 'gpt-4.1'),
                [
                    'temperature' => 0,
                    'max_tokens' => 4000,
                    'response_format' => ['type' => 'json_object'],
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('SupplierItemTranslator: LLM call failed (non-fatal)', [
                'count' => count($payload),
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $parsed = json_decode($result['content'] ?? '', true);
        if (! is_array($parsed) || ! isset($parsed['items']) || ! is_array($parsed['items'])) {
            Log::warning('SupplierItemTranslator: unreadable answer', ['content' => mb_substr((string) ($result['content'] ?? ''), 0, 300)]);

            return [];
        }

        $out = [];
        foreach ($parsed['items'] as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }
            $en = trim((string) ($row['name_en'] ?? ''));
            // Ответ «перевода» кириллицей — не перевод.
            if ($en !== '' && preg_match('/\p{Cyrillic}/u', $en) !== 1) {
                $out[(int) $row['id']] = $en;
            }
        }

        return $out;
    }
}
