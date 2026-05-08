<?php

namespace App\Services\Kb;

use App\Models\Kb\ParameterExtractor;
use App\Models\RequestItem;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Документ 3 §4.2: применение parameter_extractors к позиции.
 *
 * Универсальный движок: один сервис покрывает и декодирование артикулов
 * (LC1D09M7 → ток 9A), и парсинг свободной маркировки (Канат ∅8мм → diameter=8).
 * Различаются записи в parameter_extractors только полем source_field.
 */
class ParameterExtractionService
{
    /**
     * @return array<string, mixed> slug → value
     */
    public function extract(RequestItem $item, ?int $brandId, ?int $categoryId): array
    {
        $extractors = ParameterExtractor::query()
            ->where('is_active', true)
            ->where(function ($q) use ($categoryId) {
                $q->whereNull('category_id');
                if ($categoryId !== null) {
                    $q->orWhere('category_id', $categoryId);
                }
            })
            ->where(function ($q) use ($brandId) {
                $q->whereNull('brand_id');
                if ($brandId !== null) {
                    $q->orWhere('brand_id', $brandId);
                }
            })
            ->orderBy('priority')
            ->get();

        $extracted = [];

        foreach ($extractors as $extractor) {
            $source = $this->resolveSource($extractor->source_field, $item);
            if ($source === null || trim($source) === '') {
                continue;
            }

            // Если экстрактор привязан к маске SKU — артикул должен ей соответствовать
            if ($extractor->source_field === 'article' && $extractor->triggered_by_sku_pattern_id) {
                $pattern = $extractor->triggeredBySkuPattern;
                if ($pattern && !$this->matches($pattern->pattern, $source)) {
                    continue;
                }
            }

            $normalized = $this->applyPreNormalize($source, $extractor->pre_normalize_rules ?? []);
            $rules = $extractor->rules ?? [];

            if (!is_array($rules)) {
                continue;
            }

            foreach ($rules as $rule) {
                $slug = $rule['parameter_slug'] ?? null;
                $patterns = $rule['patterns'] ?? [];
                if (!is_string($slug) || !is_array($patterns)) {
                    continue;
                }

                // Параметр уже извлечён предыдущим (более приоритетным) экстрактором — пропускаем
                if (array_key_exists($slug, $extracted)) {
                    continue;
                }

                foreach ($patterns as $regex) {
                    if (!is_string($regex) || $regex === '') {
                        continue;
                    }
                    $delim = '/' . str_replace('/', '\\/', $regex) . '/u';
                    try {
                        if (preg_match($delim, $normalized, $matches) === 1) {
                            $rawValue = $matches[1] ?? $matches[0];
                            $value = $this->applyPostExtract(
                                $rawValue,
                                ($extractor->post_extract_rules[$slug] ?? null)
                            );
                            $extracted[$slug] = $value;
                            break;
                        }
                    } catch (Throwable $e) {
                        Log::warning('ParameterExtractionService: regex error', [
                            'extractor_id' => $extractor->id,
                            'pattern' => $regex,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return $extracted;
    }

    private function resolveSource(string $sourceField, RequestItem $item): ?string
    {
        return match ($sourceField) {
            'article' => $item->parsed_article,
            'name' => $item->parsed_name,
            'raw_text' => $item->raw_text,
            default => null,
        };
    }

    /**
     * @param array<int, array<string, string>> $rules
     */
    private function applyPreNormalize(string $source, array $rules): string
    {
        $result = $source;
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (isset($rule['from'], $rule['to'])) {
                $result = str_replace($rule['from'], $rule['to'], $result);
            } elseif (isset($rule['from_regex'], $rule['to'])) {
                $delim = '/' . str_replace('/', '\\/', $rule['from_regex']) . '/u';
                $replaced = @preg_replace($delim, $rule['to'], $result);
                if ($replaced !== null) {
                    $result = $replaced;
                }
            }
        }
        return $result;
    }

    private function applyPostExtract(string $rawValue, $rule): mixed
    {
        if (!is_array($rule)) {
            return $rawValue;
        }

        $type = $rule['type'] ?? null;

        // chain — последовательное применение нескольких трансформаций
        if ($type === 'chain' && isset($rule['steps']) && is_array($rule['steps'])) {
            $value = $rawValue;
            foreach ($rule['steps'] as $step) {
                if (is_array($step)) {
                    $value = $this->applyPostExtract(is_string($value) ? $value : (string) $value, $step);
                }
            }
            return $value;
        }

        return match ($type) {
            'literal_map' => $rule['values'][$rawValue] ?? $rawValue,
            'to_number' => $this->castToNumber($rawValue),
            'to_int' => (int) preg_replace('/\D+/', '', $rawValue),
            'lowercase' => mb_strtolower($rawValue),
            'uppercase' => mb_strtoupper($rawValue),
            'strip_spaces' => preg_replace('/\s+/u', '', $rawValue),
            'cyrillic_to_latin' => $this->cyrillicToLatin($rawValue),
            default => $rawValue,
        };
    }

    /**
     * Заменяет распространённые кириллические буквы, визуально идентичные латинским,
     * на латинские. Полезно для артикулов где русская «М» в «М7» = латинская «M».
     */
    private function cyrillicToLatin(string $value): string
    {
        $map = [
            'А' => 'A', 'В' => 'B', 'С' => 'C', 'Е' => 'E', 'Н' => 'H',
            'К' => 'K', 'М' => 'M', 'О' => 'O', 'Р' => 'P', 'Т' => 'T', 'Х' => 'X',
            'а' => 'a', 'в' => 'b', 'с' => 'c', 'е' => 'e', 'н' => 'h',
            'к' => 'k', 'м' => 'm', 'о' => 'o', 'р' => 'p', 'т' => 't', 'х' => 'x',
        ];
        return strtr($value, $map);
    }

    private function castToNumber(string $rawValue): float|int
    {
        $clean = str_replace(',', '.', trim($rawValue));
        $float = (float) $clean;
        return $float == (int) $float ? (int) $float : $float;
    }

    private function matches(string $pattern, string $value): bool
    {
        $delim = '/' . str_replace('/', '\\/', $pattern) . '/u';
        return @preg_match($delim, $value) === 1;
    }
}
