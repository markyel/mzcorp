<?php

namespace App\Services\Quotes;

use App\Models\CatalogItem;
use App\Models\OutboundQuote;
use App\Models\OutboundQuoteItem;
use App\Models\Request;
use App\Models\RequestItem;
use App\Prompts\Quotes\MatchOutboundQuoteItemsPrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\Catalog\CatalogImportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Matcher позиций исходящего КП с позициями заявки и каталогом.
 *
 * Гибридный pipeline (по решениям обсуждения 2026-05-22):
 *   Step 1. M-SKU exact → catalog_items.sku (через `CatalogImportService::normalizeArticle`,
 *           учитывает кириллический lookalike-fold). Заполняет matched_catalog_item_id.
 *   Step 2. catalog_item_id → RequestItem: если у RequestItem заявки уже стоит тот же
 *           catalog_item_id (Use-case A из CatalogResolutionService) — линкуем.
 *   Step 3. Fuzzy по article (normalized similarity ≥ 0.85) + name (substring или
 *           Levenshtein-similarity ≥ 0.7 на 2+ значащих токенах).
 *   Step 4. LLM-fallback (gpt-4o-mini, `MatchOutboundQuoteItemsPrompt`) на оставшихся
 *           unmatched позициях.
 *
 * Idempotent: повторный запуск перезатирает match_* поля, но не плодит дубликаты.
 * Возвращает массив со счётчиками для job-логов / dashboard.
 */
class OutboundQuoteItemMatcher
{
    private const FUZZY_ARTICLE_THRESHOLD = 0.85;
    private const FUZZY_NAME_THRESHOLD = 0.70;
    // Step 2.5: catalog.name → request.parsed_name. Порог ниже, чем для blind
    // fuzzy_name, потому что M-SKU уже найден в каталоге — сильный сигнал
    // правильности target'а; имя только подтверждает «та же позиция».
    private const CATALOG_NAME_THRESHOLD = 0.50;
    private const LLM_CONFIDENCE_SCORES = [
        'high' => 0.85,
        'medium' => 0.65,
        'low' => 0.45,
    ];

    public function __construct(
        private readonly OpenAIChatService $chat,
        private readonly MatchOutboundQuoteItemsPrompt $prompt,
    ) {
    }

    /**
     * @return array{
     *     total: int,
     *     by_source: array<string, int>,
     *     matched_catalog: int,
     *     matched_request: int,
     *     unmatched: int
     * }
     */
    public function match(OutboundQuote $quote): array
    {
        $quote->loadMissing(['items', 'request.items']);
        $request = $quote->request;
        if (! $request instanceof Request) {
            throw new \RuntimeException("OutboundQuote {$quote->id} has no related request");
        }

        $items = $quote->items;
        if ($items->isEmpty()) {
            return $this->emptyStats();
        }

        $stats = ['total' => $items->count()] + $this->emptyStats();

        // Step 1+2: M-SKU exact + catalog→request link (через request_item.catalog_item_id
        // ИЛИ через M-SKU извлечённый из request_item.parsed_article/name — клиент сам
        // написал SKU в заявке).
        $this->matchBySkuAndCatalog($items, $request);

        // Step 2.5: для quote_item с matched_catalog_item_id, но без matched_request_item_id —
        // попытка связать через name similarity между catalog_items.name и
        // request_items.parsed_name. Сильный сигнал каталога позволяет понизить порог.
        $this->matchByCatalogName($items, $request);

        // Step 3: Fuzzy для тех, у кого ещё нет matched_request_item_id.
        $this->matchByFuzzy($items, $request);

        // Step 4: LLM-fallback ТОЛЬКО для item'ов без resolved catalog. Если
        // matched_catalog_item_id уже найден через Step 1 — значит позиция
        // КП валидно резолвится в catalog_items, и LLM не должен переписывать
        // источник: либо в заявке клиента есть RequestItem с тем же catalog_item_id
        // (Step 2 уже подцепил), либо клиент описал позицию без M-SKU настолько
        // иначе, что Step 2/3 не нашли — это валидное «catalog hit без request match»,
        // не повод задействовать LLM с риском неправильно сопоставить с
        // другой похожей позицией.
        $stillUnmatched = $items->filter(
            fn (OutboundQuoteItem $it) => $it->matched_request_item_id === null
                && $it->matched_catalog_item_id === null
        );
        if ($stillUnmatched->isNotEmpty()) {
            $this->matchByLlm($stillUnmatched, $request);
        }

        // Финальный учёт. by_source считаем по фактическому match_source КАЖДОГО
        // item'а — это даёт корректную картину даже если шаги перезаписывали
        // источник (например catalog→request переписал sku_exact на catalog_to_request).
        foreach ($items as $it) {
            if ($it->match_source === null) {
                $it->match_source = OutboundQuoteItem::MATCH_SOURCE_UNMATCHED;
                $it->save();
            }
            $stats['by_source'][$it->match_source] = ($stats['by_source'][$it->match_source] ?? 0) + 1;
            if ($it->matched_catalog_item_id !== null) {
                $stats['matched_catalog']++;
            }
            if ($it->matched_request_item_id !== null) {
                $stats['matched_request']++;
            }
        }
        $stats['unmatched'] = $stats['total'] - $stats['matched_request'];

        // Обучение автоматчинга (шаг D, LearnedAliasService): строка КП/счёта,
        // связанная с позицией заявки ПО ИМЕНИ (не через уже существующую
        // каталожную привязку), — это менеджер в 1С выбрал конкретный товар под
        // клиентский код. Запоминаем «parsed_article → catalog_item»; шум
        // одиночных ошибок отсекает порог подтверждений (>= 2 разных документов).
        // Fail-soft: обучение не должно влиять на матчинг.
        try {
            $learnSources = [
                OutboundQuoteItem::MATCH_SOURCE_CATALOG_NAME_TO_REQUEST,
                OutboundQuoteItem::MATCH_SOURCE_FUZZY_ARTICLE,
            ];
            $learner = app(\App\Services\Catalog\LearnedAliasService::class);
            foreach ($items as $it) {
                if ($it->matched_request_item_id === null
                    || $it->matched_catalog_item_id === null
                    || ! in_array($it->match_source, $learnSources, true)) {
                    continue;
                }
                $ri = $request->items->firstWhere('id', $it->matched_request_item_id);
                $catalog = \App\Models\CatalogItem::find($it->matched_catalog_item_id);
                if ($ri !== null && $catalog !== null) {
                    $learner->learnFromManualLink($ri, $catalog, null);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('OutboundQuoteItemMatcher: alias learning failed (non-fatal)', [
                'outbound_quote_id' => $quote->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    /**
     * Step 1 + Step 1.5 + Step 2.
     *
     * Step 1   — извлечь M-SKU из quote_item.raw_article/raw_name → найти catalog_item.
     * Step 1.5 — извлечь M-SKU из request_item.parsed_article/parsed_name → построить
     *            карту sku_in_request → request_item (для случая когда клиент сам
     *            написал M-SKU в заявке, а CatalogResolutionService ещё не успел
     *            проставить catalog_item_id на RequestItem).
     * Step 2   — связать quote_item ↔ request_item по двум путям приоритета:
     *            (a) request_item.catalog_item_id == quote_item.matched_catalog_item_id;
     *            (b) M-SKU(request_item) == M-SKU(quote_item).
     *
     * @param  Collection<int, OutboundQuoteItem>  $items
     */
    private function matchBySkuAndCatalog(Collection $items, Request $request): void
    {
        // Step 1 — карта M-SKU → quote_item'ы.
        $skuToItem = [];
        foreach ($items as $item) {
            $sku = $this->extractMSku($item->raw_article)
                ?? $this->extractMSku($item->raw_name);
            if ($sku !== null) {
                $skuToItem[$sku][] = $item;
            }
        }
        if (empty($skuToItem)) {
            return;
        }

        // Bulk lookup catalog_items.sku.
        $skus = array_keys($skuToItem);
        $catalog = CatalogItem::whereIn('sku', $skus)->where('is_active', true)->get()->keyBy('sku');

        // Step 1.5 — карта M-SKU → request_item. Извлекаем M-SKU из parsed_article и
        // parsed_name каждого active RequestItem. Если несколько RequestItem'ов
        // имеют один и тот же M-SKU (редкий кейс, например клиент дублировал) —
        // оставляем первый (lowest id, request_items упорядочены by id).
        $requestSkuMap = [];
        foreach ($request->items->where('is_active', true) as $ri) {
            $riSku = $this->extractMSku($ri->parsed_article)
                ?? $this->extractMSku($ri->parsed_name);
            if ($riSku !== null && ! isset($requestSkuMap[$riSku])) {
                $requestSkuMap[$riSku] = $ri;
            }
        }

        // Step 2 — карта catalog_item_id → RequestItem (для основного пути).
        $requestItemsByCatalogId = $request->items
            ->where('is_active', true)
            ->whereNotNull('catalog_item_id')
            ->keyBy('catalog_item_id');

        foreach ($skuToItem as $sku => $quoteItems) {
            $catalogItem = $catalog->get($sku);
            if ($catalogItem === null) {
                continue;
            }

            foreach ($quoteItems as $qi) {
                $qi->matched_catalog_item_id = $catalogItem->id;
                $qi->match_score = 1.0;
                $qi->match_source = OutboundQuoteItem::MATCH_SOURCE_SKU_EXACT;
                $qi->match_reason = 'M-SKU exact: '.$sku;

                // Step 2(a) — RequestItem.catalog_item_id уже резолвлен в тот же товар.
                $reqItem = $requestItemsByCatalogId->get($catalogItem->id);
                if ($reqItem !== null) {
                    $qi->matched_request_item_id = $reqItem->id;
                    $qi->match_source = OutboundQuoteItem::MATCH_SOURCE_CATALOG_TO_REQUEST;
                    $qi->match_reason = sprintf(
                        'M-SKU %s → catalog#%d → request_item#%d',
                        $sku, $catalogItem->id, $reqItem->id
                    );
                } elseif (isset($requestSkuMap[$sku])) {
                    // Step 2(b) — клиент написал тот же M-SKU в заявке, RequestItem.catalog_item_id
                    // не проставлен (Use-case A не отработал, либо нашего товара ещё не было).
                    $reqItem = $requestSkuMap[$sku];
                    $qi->matched_request_item_id = $reqItem->id;
                    $qi->match_source = OutboundQuoteItem::MATCH_SOURCE_SKU_TO_REQUEST;
                    $qi->match_reason = sprintf(
                        'M-SKU %s найден в request_item#%d (parsed_article/name)',
                        $sku, $reqItem->id
                    );
                }

                $qi->save();
            }
        }
    }

    /**
     * Step 2.5. Для quote_item с найденным matched_catalog_item_id но без
     * matched_request_item_id — попытка связать через name similarity между
     * catalog_items.name и request_items.parsed_name (плюс quote_item.raw_name
     * как secondary signal). Порог понижен до 0.50 потому что M-SKU уже
     * найден в каталоге — это сильный сигнал, что цель найдена правильно,
     * имя только подтверждает «та же позиция в заявке».
     *
     * @param  Collection<int, OutboundQuoteItem>  $items
     */
    private function matchByCatalogName(Collection $items, Request $request): void
    {
        // Берём только quote_item'ы с catalog'ом но без request match.
        $candidates = $items->filter(
            fn (OutboundQuoteItem $it) => $it->matched_catalog_item_id !== null
                && $it->matched_request_item_id === null
        );
        if ($candidates->isEmpty()) {
            return;
        }

        // Bulk-load catalog_items для всех candidate'ов (имя нужно).
        $catalogIds = $candidates->pluck('matched_catalog_item_id')->unique()->all();
        $catalogById = CatalogItem::whereIn('id', $catalogIds)->get()->keyBy('id');

        $activeReqItems = $request->items->where('is_active', true)->values();
        if ($activeReqItems->isEmpty()) {
            return;
        }

        foreach ($candidates as $qi) {
            $catalogItem = $catalogById->get($qi->matched_catalog_item_id);
            $catalogName = $catalogItem?->name;
            $rawName = (string) $qi->raw_name;

            $bestRiId = null;
            $bestScore = 0.0;
            $bestNameSource = null; // 'catalog' | 'raw'

            foreach ($activeReqItems as $ri) {
                $reqName = (string) $ri->parsed_name;
                if ($reqName === '') {
                    continue;
                }
                // Сильнее: catalog.name vs request — это «каноничное имя нашего товара».
                if ($catalogName !== null && $catalogName !== '') {
                    $score = $this->nameSimilarity($catalogName, $reqName);
                    if ($score > $bestScore) {
                        $bestRiId = $ri->id;
                        $bestScore = $score;
                        $bestNameSource = 'catalog';
                    }
                }
                // Также: raw_name КП vs request — даёт сигнал на нестандартные описания.
                $rawScore = $this->nameSimilarity($rawName, $reqName);
                if ($rawScore > $bestScore) {
                    $bestRiId = $ri->id;
                    $bestScore = $rawScore;
                    $bestNameSource = 'raw';
                }
            }

            if ($bestRiId !== null && $bestScore >= self::CATALOG_NAME_THRESHOLD) {
                $qi->matched_request_item_id = $bestRiId;
                $qi->match_score = round($bestScore, 4);
                $qi->match_source = OutboundQuoteItem::MATCH_SOURCE_CATALOG_NAME_TO_REQUEST;
                $qi->match_reason = sprintf(
                    '%s.name ≈ request_item#%d.parsed_name (%.2f)',
                    $bestNameSource === 'catalog' ? 'catalog' : 'raw',
                    $bestRiId,
                    $bestScore
                );
                $qi->save();
            }
        }
    }

    /**
     * Step 3. Fuzzy match по article (normalized) и/или name (значащие токены).
     *
     * @param  Collection<int, OutboundQuoteItem>  $items
     */
    private function matchByFuzzy(Collection $items, Request $request): void
    {
        $activeReqItems = $request->items->where('is_active', true)->values();
        if ($activeReqItems->isEmpty()) {
            return;
        }

        foreach ($items as $qi) {
            if ($qi->matched_request_item_id !== null) {
                continue;
            }

            $bestRiId = null;
            $bestScore = 0.0;
            $bestSource = null;
            $bestReason = null;

            $qArt = CatalogImportService::normalizeArticle($qi->raw_article);

            foreach ($activeReqItems as $ri) {
                // 3a — fuzzy по article.
                if ($qArt !== null && $qArt !== '') {
                    $riArt = CatalogImportService::normalizeArticle($ri->parsed_article);
                    if ($riArt !== null && $riArt !== '') {
                        $score = $this->similarity($qArt, $riArt);
                        if ($score >= self::FUZZY_ARTICLE_THRESHOLD && $score > $bestScore) {
                            $bestRiId = $ri->id;
                            $bestScore = $score;
                            $bestSource = OutboundQuoteItem::MATCH_SOURCE_FUZZY_ARTICLE;
                            $bestReason = sprintf('article %s ≈ %s (%.2f)', $qArt, $riArt, $score);
                        }
                    }
                }

                // 3b — fuzzy по name (только если по article ничего не нашли).
                if ($bestSource !== OutboundQuoteItem::MATCH_SOURCE_FUZZY_ARTICLE) {
                    $nameScore = $this->nameSimilarity((string) $qi->raw_name, (string) $ri->parsed_name);
                    if ($nameScore >= self::FUZZY_NAME_THRESHOLD && $nameScore > $bestScore) {
                        $bestRiId = $ri->id;
                        $bestScore = $nameScore;
                        $bestSource = OutboundQuoteItem::MATCH_SOURCE_FUZZY_NAME;
                        $bestReason = sprintf('name similarity %.2f', $nameScore);
                    }
                }
            }

            if ($bestRiId !== null) {
                $qi->matched_request_item_id = $bestRiId;
                $qi->match_score = round($bestScore, 4);
                $qi->match_source = $bestSource;
                $qi->match_reason = $bestReason;
                $qi->save();
            }
        }
    }

    /**
     * Step 4. LLM-fallback на unmatched (без catalog_item_id).
     *
     * @param  Collection<int, OutboundQuoteItem>  $unmatched
     */
    private function matchByLlm(Collection $unmatched, Request $request): void
    {
        if (! config('services.openai.api_key')) {
            return;
        }

        $threshold = (float) config('services.quotes.match_score_threshold', 0.6);
        $model = (string) config('services.openai.quote_matcher_model', 'gpt-4o-mini');

        try {
            $messages = $this->prompt->build($unmatched, $request);
            $response = $this->chat->chat($messages, $model, [
                'temperature' => 0,
                'max_tokens' => 2048,
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('OutboundQuoteItemMatcher: LLM call failed', [
                'quote_id' => $unmatched->first()?->outbound_quote_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $content = (string) ($response['content'] ?? '');
        $parsed = json_decode($content, true);
        if (! is_array($parsed) || ! isset($parsed['matches']) || ! is_array($parsed['matches'])) {
            Log::warning('OutboundQuoteItemMatcher: invalid LLM JSON', [
                'raw' => mb_substr($content, 0, 400),
            ]);

            return;
        }

        $unmatchedByIndex = $unmatched->values();
        $validRequestIds = $request->items->pluck('id')->all();

        foreach ($parsed['matches'] as $m) {
            $idx = $m['quote_index'] ?? null;
            $riId = $m['request_item_id'] ?? null;
            $conf = strtolower((string) ($m['confidence'] ?? 'none'));
            $reason = isset($m['reason']) && is_string($m['reason'])
                ? mb_substr(trim($m['reason']), 0, 500)
                : null;

            if ($conf === 'none' || $idx === null || $riId === null) {
                continue;
            }
            if (! is_int($idx) || $idx < 0 || ! isset($unmatchedByIndex[$idx])) {
                continue;
            }
            if (! in_array($riId, $validRequestIds, true)) {
                continue;
            }
            $score = self::LLM_CONFIDENCE_SCORES[$conf] ?? 0.0;
            if ($score < $threshold) {
                continue;
            }

            $qi = $unmatchedByIndex[$idx];
            $qi->matched_request_item_id = (int) $riId;
            $qi->match_score = $score;
            $qi->match_source = OutboundQuoteItem::MATCH_SOURCE_LLM;
            $qi->match_reason = $reason ?: ('LLM '.$conf);
            $qi->save();
        }
    }

    /**
     * Достаёт M\d{4,} из произвольной строки. Cyrillic «М» → latin «M» ДО regex
     * (см. QualityAssessmentService::detectInternalCatalogSku — тот же паттерн).
     *
     * Дополнительно: ПРИОРИТЕТНО ищем разорванный через `\n` артикул (склейка
     * `M\d{4,6}` + перенос строки + 1-2 цифры → один артикул). Это лечит случаи
     * когда raw_name пришёл из PDF-парсера в виде «...M0943\n1» и pre-process
     * парсера не сработал (например item создан через CLI или Vision увидел
     * только верхнюю часть).
     *
     * НЕ склеиваем через обычный пробел — типичный кейс «M02704 2 шт» (артикул +
     * количество), склейка дала бы false-positive `M027042`.
     */
    private function extractMSku(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $folded = CatalogImportService::cyrillicLookalikeFold($value);

        // Приоритетный шаг — разорванный артикул через перенос строки.
        // Сначала ищем именно «\n», чтобы он не был перехвачен обычным паттерном.
        if (preg_match(
            '/(?<![\p{L}\p{N}_])(M\d{4,6})[ \t\xC2\xA0]*\r?\n[ \t\xC2\xA0]*(\d{1,2})(?![\p{L}\p{N}_])/u',
            $folded,
            $m
        ) === 1) {
            return strtoupper($m[1].$m[2]);
        }

        // Базовый шаг — обычный M\d{4,}.
        if (preg_match('/(?<![\p{L}\p{N}_])(M\d{4,})(?![\p{L}\p{N}_])/u', $folded, $m) === 1) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /**
     * Симметричная similarity 0..1 на нормализованных артикулах. PHP similar_text()
     * возвращает 0..100 percent — нормируем.
     */
    private function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    /**
     * Name similarity: пересечение значащих токенов (length ≥ 3, без стоп-слов)
     * плюс similar_text bonus. Возвращает 0..1.
     */
    private function nameSimilarity(string $a, string $b): float
    {
        $tokensA = $this->meaningfulTokens($a);
        $tokensB = $this->meaningfulTokens($b);
        if (empty($tokensA) || empty($tokensB)) {
            return 0.0;
        }

        $intersect = array_intersect($tokensA, $tokensB);
        $intersectCount = count($intersect);
        if ($intersectCount < 2) {
            return 0.0;
        }

        $unionCount = count(array_unique([...$tokensA, ...$tokensB]));
        $jaccard = $intersectCount / max(1, $unionCount);

        // Добавляем bonus от similar_text на полных строках.
        similar_text(mb_strtolower($a), mb_strtolower($b), $percent);
        $textSim = $percent / 100;

        return min(1.0, 0.6 * $jaccard + 0.4 * $textSim);
    }

    /**
     * Значащие токены: lower-case, длиной ≥ 3, без стоп-слов RU/EN.
     *
     * @return array<int, string>
     */
    private function meaningfulTokens(string $value): array
    {
        static $stop = [
            'для', 'или', 'без', 'при', 'под', 'над', 'это', 'тип', 'вид',
            'and', 'the', 'for', 'with', 'sub', 'use', 'pcs', 'qty',
        ];
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        $tokens = array_filter(
            preg_split('/\s+/u', (string) $value, -1, PREG_SPLIT_NO_EMPTY),
            fn (string $t) => mb_strlen($t) >= 3 && ! in_array($t, $stop, true)
        );

        return array_values(array_unique($tokens));
    }

    /**
     * @return array{by_source: array<string, int>, matched_catalog: int, matched_request: int, unmatched: int}
     */
    private function emptyStats(): array
    {
        return [
            'by_source' => [],
            'matched_catalog' => 0,
            'matched_request' => 0,
            'unmatched' => 0,
        ];
    }
}
