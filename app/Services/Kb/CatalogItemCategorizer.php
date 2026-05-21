<?php

namespace App\Services\Kb;

use App\Models\CatalogItem;
use App\Models\Kb\EquipmentCategory;
use App\Prompts\Kb\ClassifyCatalogItemPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Классифицирует один CatalogItem в KB EquipmentCategory.
 *
 * Двухэтапный пайплайн (Phase B / 2026-05-21):
 *   1. Rule-based матчинг по synonyms KB. Smart-substring (lowercase, без
 *      word-boundary, мульти-форма цеп→цеп) — терпим морфологию.
 *      Выбираем категорию у которой максимум synonym-хитов в haystack.
 *   2. Если 0 матчей или >1 равных лидеров → LLM (gpt-4o-mini) с полным
 *      списком категорий. Confidence < 0.6 → возвращаем null.
 *
 * Возвращает: ['category' => EquipmentCategory|null, 'confidence' => float, 'method' => string].
 */
class CatalogItemCategorizer
{
    private const LLM_MODEL = 'gpt-4o-mini';
    private const LLM_TEMPERATURE = 0.1;
    private const LLM_MAX_TOKENS = 200;
    private const CONFIDENCE_THRESHOLD = 0.6;

    public function __construct(
        private readonly OpenAIChatService $openai,
    ) {}

    /**
     * Кэш категорий для batch-режима — чтобы не делать N запросов в БД.
     *
     * @var Collection<int, EquipmentCategory>|null
     */
    private ?Collection $categoriesCache = null;

    public function preloadCategories(): Collection
    {
        if ($this->categoriesCache === null) {
            $this->categoriesCache = EquipmentCategory::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->get(['id', 'name', 'synonyms', 'description']);
        }
        return $this->categoriesCache;
    }

    /**
     * @param  bool  $allowLlm  если false — только rule-based, без вызова LLM.
     * @return array{category: ?EquipmentCategory, confidence: float, method: string, reason?: string}
     */
    public function categorize(CatalogItem $item, bool $allowLlm = true): array
    {
        $categories = $this->preloadCategories();

        // 1. Rule-based
        $ruleResult = $this->matchByRules($item, $categories);
        if ($ruleResult['category'] !== null) {
            return $ruleResult;
        }

        if (! $allowLlm) {
            return [
                'category' => null,
                'confidence' => 0.0,
                'method' => 'rule_no_match',
            ];
        }

        // 2. LLM fallback
        return $this->matchByLlm($item, $categories);
    }

    /**
     * @param  Collection<int, EquipmentCategory>  $categories
     * @return array{category: ?EquipmentCategory, confidence: float, method: string}
     */
    private function matchByRules(CatalogItem $item, Collection $categories): array
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            (string) $item->name,
            (string) $item->name_en,
            (string) $item->unit_name,
            (string) $item->part_type,
            is_array($item->units) ? implode(' ', $item->units) : '',
            (string) $item->placement,
        ])));

        if ($haystack === '') {
            return ['category' => null, 'confidence' => 0.0, 'method' => 'rule_no_haystack'];
        }

        $scores = [];
        foreach ($categories as $cat) {
            $score = 0;
            $hits = [];

            // Имя категории — самый сильный сигнал
            $namelc = mb_strtolower($cat->name);
            if ($namelc !== '' && mb_strpos($haystack, $namelc) !== false) {
                $score += 3;
                $hits[] = $cat->name;
            }

            // Синонимы — отдельно
            $syns = is_array($cat->synonyms) ? $cat->synonyms : [];
            foreach ($syns as $syn) {
                $synlc = mb_strtolower(trim((string) $syn));
                if ($synlc === '' || mb_strlen($synlc) < 3) {
                    continue;
                }
                if (mb_strpos($haystack, $synlc) !== false) {
                    $score += 1;
                    $hits[] = $syn;
                }
            }

            if ($score > 0) {
                $scores[$cat->id] = ['score' => $score, 'hits' => $hits, 'category' => $cat];
            }
        }

        if ($scores === []) {
            return ['category' => null, 'confidence' => 0.0, 'method' => 'rule_no_match'];
        }

        uasort($scores, fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = array_values($scores)[0];
        $second = array_values($scores)[1] ?? null;

        // Если несколько с одинаковым максимумом — не уверены, отправим в LLM.
        if ($second && $second['score'] === $top['score']) {
            return ['category' => null, 'confidence' => 0.0, 'method' => 'rule_ambiguous'];
        }

        // Confidence = 1.0 при ≥3 хитах, 0.85 при ≥2, 0.7 при 1.
        $confidence = match (true) {
            $top['score'] >= 3 => 1.0,
            $top['score'] >= 2 => 0.85,
            default => 0.7,
        };

        return [
            'category' => $top['category'],
            'confidence' => $confidence,
            'method' => 'rule_match',
        ];
    }

    /**
     * @param  Collection<int, EquipmentCategory>  $categories
     * @return array{category: ?EquipmentCategory, confidence: float, method: string, reason?: string}
     */
    private function matchByLlm(CatalogItem $item, Collection $categories): array
    {
        try {
            $messages = ClassifyCatalogItemPrompt::build($item, $categories);
            $resp = $this->openai->chat($messages, self::LLM_MODEL, [
                'temperature' => self::LLM_TEMPERATURE,
                'max_tokens' => self::LLM_MAX_TOKENS,
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (Throwable $e) {
            Log::warning('CatalogItemCategorizer: LLM call failed', [
                'catalog_item_id' => $item->id,
                'sku' => $item->sku,
                'error' => $e->getMessage(),
            ]);
            return ['category' => null, 'confidence' => 0.0, 'method' => 'llm_error'];
        }

        $content = trim((string) ($resp['content'] ?? ''));
        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            Log::warning('CatalogItemCategorizer: LLM response not JSON', [
                'catalog_item_id' => $item->id,
                'content' => mb_substr($content, 0, 200),
            ]);
            return ['category' => null, 'confidence' => 0.0, 'method' => 'llm_bad_json'];
        }

        $catId = isset($decoded['category_id']) ? (int) $decoded['category_id'] : 0;
        $confidence = (float) ($decoded['confidence'] ?? 0);
        $reason = (string) ($decoded['reason'] ?? '');

        if ($catId <= 0) {
            return [
                'category' => null,
                'confidence' => $confidence,
                'method' => 'llm_no_match',
                'reason' => $reason,
            ];
        }
        if ($confidence < self::CONFIDENCE_THRESHOLD) {
            return [
                'category' => null,
                'confidence' => $confidence,
                'method' => 'llm_low_confidence',
                'reason' => $reason,
            ];
        }

        $cat = $categories->firstWhere('id', $catId);
        if (! $cat) {
            return [
                'category' => null,
                'confidence' => $confidence,
                'method' => 'llm_bad_id',
                'reason' => $reason,
            ];
        }

        return [
            'category' => $cat,
            'confidence' => $confidence,
            'method' => 'llm_match',
            'reason' => $reason,
        ];
    }
}
