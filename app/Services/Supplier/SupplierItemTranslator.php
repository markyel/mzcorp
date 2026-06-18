<?php

namespace App\Services\Supplier;

use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;

/**
 * Перевод названий позиций RU→EN для письма-запроса иностранному поставщику
 * (Фаза 3.2, язык общения). gpt-4o-mini, батчем, fail-safe: при ошибке/мусоре
 * возвращает пустой массив (вызывающий оставляет прежние значения). Бренды,
 * коды моделей, артикулы, размеры и единицы сохраняем как есть — переводим
 * только описательные слова.
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

        $system = 'You translate elevator and escalator spare-part names from Russian to English for a price request (RFQ) sent to a foreign supplier. '
            . 'Keep brand names, manufacturer names, model codes, article/part numbers, dimensions and measurement values unchanged. '
            . 'Translate only the descriptive Russian words into natural technical English. Do not add or drop information. '
            . 'Return STRICT JSON: {"items":[{"id":<int>,"name_en":"<english>"}]}. No commentary.';

        try {
            $result = $this->openai->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => json_encode(['items' => $payload], JSON_UNESCAPED_UNICODE)],
                ],
                config('services.openai.intent_model', 'gpt-4o-mini'),
                [
                    'temperature' => 0,
                    'max_tokens' => 2000,
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
            return [];
        }

        $out = [];
        foreach ($parsed['items'] as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }
            $en = trim((string) ($row['name_en'] ?? ''));
            if ($en !== '') {
                $out[(int) $row['id']] = $en;
            }
        }

        return $out;
    }
}
