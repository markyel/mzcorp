<?php

namespace App\Services\Kb;

use App\Models\Kb\EquipmentCategory;
use App\Models\Kb\RequestContext;
use App\Models\RequestItem;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Документ 3 §3: оркестратор decision tree для оценки одной позиции.
 *
 * Идемпотентен: каждый вызов перечитывает позицию, полностью пересобирает
 * payload с нуля и перезаписывает status и payload.
 */
class QualityAssessmentService
{
    public function __construct(
        private readonly ArticleClassificationService $articleClassifier,
        private readonly ParameterExtractionService $extractor,
        private readonly CategoryRefinementService $refiner,
        private readonly BrandResolutionService $brandResolver,
        private readonly EquipmentUnitMatchingService $unitMatcher,
        private readonly RuleEvaluationService $ruleEvaluator,
        private readonly QuestionGenerationService $questionGenerator,
    ) {}

    public function assessItem(int $itemId): void
    {
        $item = RequestItem::with('request.context')->findOrFail($itemId);
        $context = $item->request?->context;

        $result = $this->assessTransient($item, $context);

        $item->quality_assessment_status = $result['status'];
        $item->quality_assessment_payload = $result['payload'];
        $item->save();

        if (($result['status'] ?? null) === 'assessment_failed') {
            Log::error('QualityAssessmentService: failed', [
                'item_id' => $itemId,
                'phase' => $result['payload']['phase'] ?? null,
                'error' => $result['payload']['error']['message'] ?? null,
            ]);
        }
    }

    /**
     * Чистая, без I/O оценка: запускает decision tree (enrichment → evaluation
     * → questions) на in-memory RequestItem с явно переданным RequestContext.
     *
     * НЕ делает $item->save() и не выбрасывает запросов на загрузку relations.
     * Мутирует переданный $item (manufacturer_brand_id, identification_category_id,
     * equipment_unit_id, quality_assessment_status) — caller сам решает, что с ним
     * делать дальше.
     *
     * Используется:
     *   - assessItem(int)          — для админского RequestItem flow с persist'ом.
     *   - NeedAssessmentService    — для Cabinet\Need через transient адаптер.
     *
     * @return array{status: string, payload: array<string, mixed>}
     */
    public function assessTransient(RequestItem $item, ?RequestContext $context): array
    {
        $payload = [
            'phase' => null,
            'started_at' => now()->toIso8601String(),
        ];

        // MyLift adaptation (Phase 2.0+):
        // Позиция с внутренним MyLift-SKU (формат «M\d{4,}» в article) —
        // её категория/бренд должны идти из корпоративной базы, а не из
        // LLM-цепочки. Помечаем internal_catalog_pending и не запускаем
        // ни extractor, ни refiner, ни brand resolver. Когда каталог появится
        // (открытый вопрос #1 в MEMORY) — batch-резолв пройдёт по этим items.
        $internalSku = $this->detectInternalCatalogSku($item);
        if ($internalSku !== null) {
            $payload['phase'] = 'completed';
            $payload['assessed_at'] = now()->toIso8601String();
            $payload['reason'] = 'internal_catalog_pending';
            $payload['internal_catalog_sku'] = $internalSku;

            return [
                'status' => 'internal_catalog_pending',
                'payload' => $this->finalizePayload($payload),
            ];
        }

        try {
            $payload = $this->runEnrichment($item, $context, $payload);

            // === Phase 2: оценка ===
            $payload['phase'] = 'evaluation';

            /** @var EquipmentCategory|null $detailedCategory */
            $detailedCategory = $payload['__detailed_category_object'] ?? null;
            unset($payload['__detailed_category_object']);

            if ($detailedCategory === null) {
                // MyLift adaptation (Phase 2.0):
                // Если KB не подобрал детальную категорию, НО бренд резолвлен
                // — это сигнал «полезные данные есть, но справочнику не хватает
                // правил». Оператор видит chip-attn (insufficient) и понимает
                // что нужно проверить категорию вручную, а не «забейте, нет
                // правил». Без brand_id остаётся честный not_covered.
                $brandKnown = ! empty($payload['resolved_brand_id']);
                $status = $brandKnown ? 'insufficient' : 'not_covered';

                $item->quality_assessment_status = $status;
                $payload['reason'] = $brandKnown
                    ? 'detailed_category_not_resolved_but_brand_known'
                    : 'detailed_category_not_resolved';

                // Если у позиции нет ни одного «лифтового» индикатора — добавим
                // вопрос-уточнение к клиенту: «эта позиция вообще для лифта?»
                if ($this->shouldAskEquipmentRelevance($item, $context)) {
                    $payload['equipment_relevance_question'] = [
                        'reason' => 'no_lift_indicators',
                        'text' => sprintf(
                            'Уточните, пожалуйста, позиция «%s» предназначена для лифта или эскалатора (и в каком узле используется)? Если это не лифтовое оборудование — отметьте, чтобы мы исключили её из заявки.',
                            mb_substr((string) $item->parsed_name, 0, 120)
                        ),
                    ];
                }

                return [
                    'status' => $status,
                    'payload' => $this->finalizePayload($payload),
                ];
            }

            $brandId = $payload['resolved_brand_id'] ?? null;
            $available = $payload['available_parameters'] ?? [];

            $evaluation = $this->ruleEvaluator->evaluate($detailedCategory, $brandId, $available);
            $payload['rule_evaluation'] = $evaluation;

            if ($evaluation['is_sufficient']) {
                $item->quality_assessment_status = 'sufficient';
                return [
                    'status' => 'sufficient',
                    'payload' => $this->finalizePayload($payload),
                ];
            }

            // === Phase 3: вопросы ===
            $payload['phase'] = 'questions';
            $questions = $this->questionGenerator->generate($item, $detailedCategory, $brandId, $evaluation);
            $payload['questions_to_ask'] = $questions;

            $item->quality_assessment_status = 'insufficient';
            return [
                'status' => 'insufficient',
                'payload' => $this->finalizePayload($payload),
            ];
        } catch (Throwable $e) {
            $payload['error'] = [
                'message' => $e->getMessage(),
                'phase_at_failure' => $payload['phase'] ?? null,
            ];
            $item->quality_assessment_status = 'assessment_failed';
            return [
                'status' => 'assessment_failed',
                'payload' => $this->finalizePayload($payload),
            ];
        }
    }

    /**
     * Phase 1 — обогащение позиции.
     *
     * Context передаётся параметром: для admin-RequestItem это
     * $item->request->context, для cabinet-Need транзиентный объект
     * с одной equipment_unit (см. NeedAssessmentService).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function runEnrichment(RequestItem $item, ?RequestContext $context, array $payload): array
    {
        $payload['phase'] = 'enrichment';

        $mentionedSources = $context?->mentioned_sources ?? [];

        // 1.1 Классификация артикула
        $classification = $this->articleClassifier->classify($item->parsed_article, $mentionedSources);
        $payload['article_classification'] = $classification;

        // 1.2 Если manufacturer_sku → бренд
        $brandId = null;
        if (($classification['type'] ?? null) === 'manufacturer_sku' && !empty($classification['matched_brand_id'])) {
            $brandId = (int) $classification['matched_brand_id'];
            $item->manufacturer_brand_id = $brandId;
        }

        // 1.3 Глобальные экстракторы (без category_id)
        $extractedParams = $this->extractor->extract($item, $brandId, null);
        $payload['extracted_parameters'] = $extractedParams;

        // 1.4 Уточнение детальной категории.
        // Если категория уже задана (курaтором через UI «Научить» или предыдущим запуском)
        // и активна — используем её вместо запуска CategoryRefinementService.
        // Это уважает ручной выбор куратора: грубая может быть «Прочее» (без авто-кандидатов),
        // но куратор знает правильную детальную категорию и не должен терять её при reassess.
        $detailedCategory = null;
        if ($item->identification_category_id) {
            $existing = EquipmentCategory::where('id', $item->identification_category_id)
                ->where('is_active', true)
                ->first();
            if ($existing) {
                $detailedCategory = $existing;
                $payload['detailed_category'] = [
                    'id' => $existing->id,
                    'slug' => $existing->slug,
                    'name' => $existing->name,
                    'confidence' => 1.0,
                ];
                $payload['detailed_category_decision'] = 'preserved_from_previous_or_manual';
            }
        }

        if (!$detailedCategory) {
            $coarse = $item->category;
            $refined = $this->refiner->refine($item, $brandId, $extractedParams, $coarse);

            if ($refined) {
                /** @var EquipmentCategory $detailedCategory */
                $detailedCategory = $refined['category'];
                $item->identification_category_id = $detailedCategory->id;
                $payload['detailed_category'] = [
                    'id' => $detailedCategory->id,
                    'slug' => $detailedCategory->slug,
                    'name' => $detailedCategory->name,
                    'confidence' => $refined['confidence'],
                ];
                $payload['detailed_category_decision'] = $refined['method'];
            } else {
                $payload['detailed_category'] = null;
                $payload['detailed_category_decision'] = 'unmatched';
            }
        }

        // 1.5 Категорийные экстракторы (если категория определена)
        if ($detailedCategory) {
            $additional = $this->extractor->extract($item, $brandId, $detailedCategory->id);
            // Не перезаписываем то, что уже извлекли
            foreach ($additional as $slug => $value) {
                if (!array_key_exists($slug, $extractedParams)) {
                    $extractedParams[$slug] = $value;
                }
            }
            $payload['extracted_parameters'] = $extractedParams;
        }

        // 1.6 Бренд через BrandResolutionService (если ещё не определён).
        // Строим временный available_parameters снимок: parsed_params (мапированные через aliases)
        // + extractedParams. Это даёт резолверу шанс увидеть lift_brand=ЩЛЗ/OTIS/etc.,
        // когда n8n не положил его в parsed_brand, но он есть в parsed_params.
        if ($brandId === null) {
            $parsedParamsForBrand = is_array($item->parsed_params ?? null) ? $item->parsed_params : [];
            $brandSnapshot = array_merge(
                $parsedParamsForBrand,
                $this->mapParsedParamsViaAliases($parsedParamsForBrand),
                $extractedParams
            );
            $brandId = $this->brandResolver->resolve($item, $detailedCategory, $brandSnapshot, $context);
            if ($brandId !== null) {
                $item->manufacturer_brand_id = $brandId;
            }
        }
        $payload['resolved_brand_id'] = $brandId;

        // 1.7 Привязка к единице оборудования
        $matchedUnitId = $this->unitMatcher->match($item, $detailedCategory, $context);
        if ($matchedUnitId !== null) {
            $item->equipment_unit_id = $matchedUnitId;
        }

        // 1.8 Унаследованные параметры от единицы оборудования
        $inheritedParams = [];
        if ($matchedUnitId !== null && $context) {
            $unit = $context->findUnit($matchedUnitId);
            if (is_array($unit)) {
                $inheritedParams = array_filter([
                    'lift_brand' => $unit['brand'] ?? null,
                    'lift_model' => $unit['model'] ?? null,
                    'equipment_unit_label' => $unit['label'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
            }
        }
        $payload['inherited_parameters'] = $inheritedParams;

        // 1.9 Дополнительные параметры из полей RequestItem (мост между n8n-парсером и KB-правилами).
        //
        // n8n уже извлекает артикул в parsed_article и помечает фото в data_source ∈ {photo, mixed}.
        // Эти данные мапим в слаги параметров идентификации, чтобы правила их видели.
        $itemFieldParams = [];

        // parsed_article от n8n (включая артикул, снятый Vision'ом с шильдика на фото)
        // → manufacturer_article из identification_parameters
        $article = trim((string) ($item->parsed_article ?? ''));
        if ($article !== '') {
            $itemFieldParams['manufacturer_article'] = $article;
        }

        // parsed_brand от n8n → lift_brand (для правил идентификации, требующих марку лифта)
        $brand = trim((string) ($item->parsed_brand ?? ''));
        if ($brand !== '') {
            $itemFieldParams['lift_brand'] = $brand;
        }

        // data_source ∈ {photo, mixed} означает: n8n проанализировал фото Vision'ом
        // и привязал его к этой позиции. Любой photo_/drawing/product_photo-параметр покрыт.
        // Точнее различить какое именно фото (шильдик / чертёж / общий вид) без отдельного
        // Vision-классификатора нельзя — пускаем как доступные все, чтобы не задавать
        // лишних вопросов клиенту. Куратор может убрать вручную если фото нерелевантно.
        $photoEvidenceFromN8n = in_array($item->data_source, ['photo', 'mixed'], true);
        if ($photoEvidenceFromN8n) {
            $photoSlugs = [
                'photo_nameplate',
                'photo_button_front',
                'photo_button_back',
                'photo_skate_label',
                'product_photo',
                'technical_drawing',
            ];
            foreach ($photoSlugs as $photoSlug) {
                $itemFieldParams[$photoSlug] = true;
            }
            $payload['photos_attached_by_n8n'] = true;
        }

        $payload['item_field_parameters'] = $itemFieldParams;

        // 1.10 Все доступные параметры (с маппингом parsed_params через aliases параметров)
        $parsedParams = is_array($item->parsed_params ?? null) ? $item->parsed_params : [];

        // n8n кладёт ключи в parsed_params под русскими именами («диаметр», «ширина»),
        // а KB-правила требуют английские slug'и («diameter_mm», «width_mm»).
        // Маппим через identification_parameters.aliases без изменения n8n-промпта.
        $mappedParsedParams = $this->mapParsedParamsViaAliases($parsedParams);

        $payload['available_parameters'] = array_merge(
            $parsedParams,                  // оригинальные ключи — на случай если правило их использует напрямую
            $mappedParsedParams,            // мапированные на канонические slug'и
            $extractedParams,
            $inheritedParams,
            $itemFieldParams
        );

        // Прокидываем объект категории для phase 2 (через служебный ключ, удаляется в assessTransient)
        $payload['__detailed_category_object'] = $detailedCategory;

        // Промежуточный snapshot пишем только в payload — assessItem(int) сделает save
        // в самом конце; для transient режима caller сам решает, что делать с partial payload.
        $item->quality_assessment_payload = $this->stripInternals($payload);

        return $payload;
    }

    /**
     * Финализирует payload (помечает phase=completed, добавляет timestamp/version
     * и удаляет служебные ключи). НЕ сохраняет в БД — это делает caller (для
     * RequestItem — assessItem; для Need — NeedAssessmentService).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function finalizePayload(array $payload): array
    {
        $payload['phase'] = 'completed';
        $payload['assessed_at'] = now()->toIso8601String();
        $payload['assessed_by_version'] = config('lazylift.qa_module_version');

        return $this->stripInternals($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function stripInternals(array $payload): array
    {
        return collect($payload)
            ->reject(fn ($_, $key) => str_starts_with((string) $key, '__'))
            ->all();
    }

    /**
     * Маппинг ключей parsed_params на канонические slug'и через aliases.
     *
     * Например, parsed_params = ['диаметр' => '127 мм', 'ширина' => '30 мм']
     * → ['diameter_mm' => '127 мм', 'width_mm' => '30 мм'] (с дополнительной
     * нормализацией в число)
     *
     * @param array<string, mixed> $parsedParams
     * @return array<string, mixed>
     */
    private function mapParsedParamsViaAliases(array $parsedParams): array
    {
        if (empty($parsedParams)) {
            return [];
        }

        // Кэш всех параметров с алиасами (alias_lc → slug)
        static $aliasIndex = null;
        if ($aliasIndex === null) {
            $aliasIndex = [];
            $allParams = \App\Models\Kb\IdentificationParameter::active()->get(['slug', 'aliases']);
            foreach ($allParams as $p) {
                foreach ($p->aliases ?? [] as $alias) {
                    if (!is_string($alias) || $alias === '') continue;
                    $aliasIndex[mb_strtolower(trim($alias))] = $p->slug;
                }
            }
        }

        $mapped = [];
        foreach ($parsedParams as $key => $value) {
            if (!is_string($key)) continue;
            $keyLc = mb_strtolower(trim($key));

            // Точное совпадение алиаса
            if (isset($aliasIndex[$keyLc])) {
                $canonicalSlug = $aliasIndex[$keyLc];
                $normalized = $this->extractNumericValue($value);
                $mapped[$canonicalSlug] = $normalized ?? $value;
            }
        }

        return $mapped;
    }

    /**
     * Извлекает первое число из значения (для случаев типа "127 мм", "30мм", "5,5 мм").
     */
    private function extractNumericValue(mixed $value): float|int|null
    {
        if (is_numeric($value)) {
            $num = (float) $value;
            return $num == (int) $num ? (int) $num : $num;
        }
        if (!is_string($value)) return null;
        if (preg_match('/(\d+(?:[.,]\d+)?)/', $value, $m)) {
            $num = (float) str_replace(',', '.', $m[1]);
            return $num == (int) $num ? (int) $num : $num;
        }
        return null;
    }

    /**
     * Должна ли быть задана клиенту fallback-уточнение «это вообще для лифта?».
     *
     * Возвращает true, если у позиции НЕТ ни одного «лифтового» индикатора:
     *   1) бренд резолвен (manufacturer_brand_id)
     *   2) parsed_article совпадает с одним из brand_sku_patterns
     *   3) equipment_unit_id привязан
     *   4) у заявки есть хотя бы одна equipment_unit в контексте
     *   5) в parsed_name/raw_text есть лифтовый keyword
     *
     * Используется только в случае, когда detailed_category не нашлась —
     * чтобы НЕ слать поставщикам нелифтовое оборудование (часы, термостаты,
     * хомуты и т.п.), а спросить клиента сразу.
     */
    private function shouldAskEquipmentRelevance(RequestItem $item, ?RequestContext $context): bool
    {
        // 1. Бренд уже резолвен
        if ($item->manufacturer_brand_id !== null) {
            return false;
        }

        // 2. parsed_article совпадает с одним из brand_sku_patterns
        $article = trim((string) ($item->parsed_article ?? ''));
        if ($article !== '') {
            $patterns = \App\Models\Kb\BrandSkuPattern::query()
                ->where('is_active', true)
                ->pluck('pattern');
            foreach ($patterns as $pattern) {
                if (!is_string($pattern) || $pattern === '') continue;
                if (@preg_match("/{$pattern}/i", $article) === 1) {
                    return false;
                }
            }
        }

        // 3. equipment_unit_id привязан
        if ($item->equipment_unit_id !== null) {
            return false;
        }

        // 4. контекст заявки имеет хотя бы одну equipment_unit
        if ($context !== null) {
            $units = $context->equipment_units ?? [];
            if (is_array($units) && count($units) > 0) {
                return false;
            }
        }

        // 5. лифтовые keyword'ы в name/raw_text
        $text = mb_strtolower(($item->parsed_name ?? '') . ' ' . ($item->raw_text ?? ''));
        if (trim($text) !== '') {
            $keywords = [
                'лифт', 'эскалатор', 'траволатор', 'кабин', 'шахт',
                'дверь', 'двер', 'тяговый', 'привод', 'лебёдк', 'лебедк',
                'противовес', 'редуктор', 'двигатель', 'мотор',
                'направляющ', 'башмак', 'вкладыш', 'отводк',
                'ловитель', 'буфер', 'ограничитель', 'шкив',
                'lift', 'elevator', 'escalator', 'hoist', 'traction',
            ];
            foreach ($keywords as $kw) {
                if (mb_strpos($text, $kw) !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * MyLift adaptation (Phase 2.0+).
     *
     * Возвращает первый внутренний MyLift-SKU из article позиции, либо null.
     * Формат: «M» + 4+ цифр («M02016», «M02804», «M07232»). Извлекаем regex'ом
     * с unicode word-boundaries — чтобы поймать SKU как в чистом виде, так и
     * внутри составных строк типа «LOP2, HBB M02016». Префиксы букв с
     * не-нашими SKU (например «MAIN5», «ML123», «OEM-M02016») — отбрасываем
     * за счёт lookbehind на не-letter/digit.
     */
    private function detectInternalCatalogSku(RequestItem $item): ?string
    {
        $article = (string) ($item->parsed_article ?? '');
        if ($article === '') {
            return null;
        }

        $pattern = '/(?<![\p{L}\p{N}_])(M\d{4,})(?![\p{L}\p{N}_])/u';
        if (preg_match($pattern, $article, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
