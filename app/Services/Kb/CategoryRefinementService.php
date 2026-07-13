<?php

namespace App\Services\Kb;

use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\ManufacturerBrand;
use App\Models\RequestItem;
use App\Prompts\Kb\CategoryRefinementPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Документ 3 §4.3: уточнение детальной категории.
 *
 * Алгоритм:
 *  1. Кандидаты по грубой категории.
 *  2. 0 кандидатов → null. 1 кандидат → возвращаем без LLM.
 *  3. Детерминированный матчинг по синонимам.
 *  4. LLM-классификация с порогом confidence ≥ 0.6.
 */
class CategoryRefinementService
{
    private const LLM_MODEL = 'gpt-4o';
    private const LLM_TEMPERATURE = 0.1;
    private const LLM_MAX_TOKENS = 400;
    private const CONFIDENCE_THRESHOLD = 0.6;

    public function __construct(
        private readonly OpenAIChatService $openai,
    ) {}

    /**
     * @return array{category: EquipmentCategory, confidence: float, method: string}|null
     */
    public function refine(
        RequestItem $item,
        ?int $brandId,
        array $extractedParameters,
        ?string $coarseCategory
    ): ?array {
        $coarseTrimmed = $coarseCategory !== null ? trim($coarseCategory) : '';

        $candidates = collect();
        if ($coarseTrimmed !== '') {
            $candidates = EquipmentCategory::query()
                ->where('is_active', true)
                ->whereHas('coarseCategories', fn ($q) => $q->where('coarse_category', $coarseTrimmed))
                ->get();
        }

        // Fallback: грубая null/пустая или к ней не привязано ни одной детальной
        // (например, «Прочее» — n8n не разобрался) → ищем по synonyms среди ВСЕХ
        // активных категорий. LLM в этом режиме НЕ запускаем — 39 кандидатов
        // дают слишком много шума.
        $useFallback = $candidates->isEmpty();
        if ($useFallback) {
            $matchedBySynonym = $this->matchBySynonym(
                $item,
                EquipmentCategory::query()->where('is_active', true)->get()
            );
            if ($matchedBySynonym) {
                return [
                    'category' => $matchedBySynonym,
                    'confidence' => 0.8,
                    'method' => 'fallback_synonym_match',
                ];
            }
            return null;
        }

        if ($candidates->count() === 1) {
            return [
                'category' => $candidates->first(),
                'confidence' => 1.0,
                'method' => 'single_candidate',
            ];
        }

        // Детерминированный матчинг по синонимам в рамках кандидатов грубой
        $matchedBySynonym = $this->matchBySynonym($item, $candidates);
        if ($matchedBySynonym) {
            return [
                'category' => $matchedBySynonym,
                'confidence' => 0.9,
                'method' => 'matched_by_synonym',
            ];
        }

        // LLM-классификация
        return $this->refineWithLlm($item, $candidates, $brandId, $extractedParameters);
    }

    private function matchBySynonym(RequestItem $item, $candidates): ?EquipmentCategory
    {
        $itemText = mb_strtolower(
            ($item->parsed_name ?? '') . ' ' . ($item->raw_text ?? '')
        );

        if (trim($itemText) === '') {
            return null;
        }

        // Выбираем категорию по САМОМУ ДЛИННОМУ (специфичному) совпавшему
        // синониму, а не по первому в порядке кандидатов из БД.
        //
        // MyLift fix (M-2026-7676): раньше метод возвращал первую категорию,
        // у которой матчился ЛЮБОЙ синоним. Короткий общий синоним «drive»
        // (Частотный преобразователь) перебивал специфичный «плата привода»
        // (Плата управления) только потому, что frequency_converter шёл раньше
        // по id. «Плата привода лифта Wittur ECO + Drive» уезжала в частотники.
        // Теперь длина совпадения решает: «плата привода» (13) > «drive» (5).
        $bestCat = null;
        $bestLen = 0;

        foreach ($candidates as $cat) {
            $synonyms = $cat->synonyms ?? [];
            if (!is_array($synonyms)) {
                continue;
            }
            foreach ($synonyms as $syn) {
                if (!is_string($syn) || trim($syn) === '') {
                    continue;
                }
                $synLower = mb_strtolower(trim($syn));

                // Голый mb_strpos ловит ложные матчи на коротких аббревиатурах.
                // Пример: synonym «ОС» (Ограничитель Скорости) → ос → substring
                // найден в «п<ос>т» (слово «пост»), и «Пост вызывной LOP2 OTIS»
                // уезжал в speed_governor.
                //
                // PHP `\b` даже с /u флагом не всегда классифицирует кириллицу
                // как word-character (зависит от PCRE/UCP режима — у нас в сборке
                // \b ставится между «п» и «о»). Используем явные Unicode
                // word-boundaries через [\p{L}\p{N}_] lookahead/lookbehind.
                $pattern = '/(?<![\p{L}\p{N}_])'
                    . preg_quote($synLower, '/')
                    . '(?![\p{L}\p{N}_])/u';
                if (preg_match($pattern, $itemText) === 1) {
                    $len = mb_strlen($synLower);
                    // Строгое `>`: при равной длине выигрывает первый кандидат
                    // (стабильность прежнего поведения).
                    if ($len > $bestLen) {
                        $bestLen = $len;
                        $bestCat = $cat;
                    }
                }
            }
        }

        return $bestCat;
    }

    private function refineWithLlm(
        RequestItem $item,
        $candidates,
        ?int $brandId,
        array $extractedParameters
    ): ?array {
        try {
            $brandName = $brandId ? ManufacturerBrand::find($brandId)?->name : null;
            $messages = CategoryRefinementPrompt::build($item, $candidates, $brandName, $extractedParameters);

            $response = $this->openai->chat($messages, self::LLM_MODEL, [
                'response_format' => ['type' => 'json_object'],
                'temperature' => self::LLM_TEMPERATURE,
                'max_tokens' => self::LLM_MAX_TOKENS,
            ]);

            $content = (string) ($response['content'] ?? '');
            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                return null;
            }

            $slug = $parsed['category_slug'] ?? null;
            $confidence = isset($parsed['confidence']) ? (float) $parsed['confidence'] : 0.0;

            if (!is_string($slug) || $slug === '' || $confidence < self::CONFIDENCE_THRESHOLD) {
                return null;
            }

            $matched = $candidates->firstWhere('slug', $slug);
            if (!$matched) {
                return null;
            }

            return [
                'category' => $matched,
                'confidence' => $confidence,
                'method' => 'matched_by_llm',
            ];
        } catch (Throwable $e) {
            Log::warning('CategoryRefinementService: LLM call failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
