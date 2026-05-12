<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use App\Models\CatalogItemEmbedding;
use App\Models\RequestItem;
use App\Prompts\Catalog\ValidateCatalogMatchPrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\AI\OpenAIEmbeddingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2 use-case C: семантический матчинг позиций заявок по name через
 * pgvector-эмбеддинги каталога.
 *
 * Pipeline:
 *  1. buildText($catalogItem) — собирает «индексируемую» строку
 *     (brand, part_type, unit_name, brand_article, name, name_en).
 *  2. syncOne / syncBatch — посчитать source_hash; если он не совпадает
 *     с сохранённым в catalog_item_embeddings, дёргаем OpenAI Embeddings,
 *     записываем (или обновляем) вектор.
 *  3. matchByRequestItem — для несматченной RequestItem строим
 *     query text по тому же рецепту (parsed_brand + part_type + parsed_name +
 *     parsed_article), embed'им один раз, и ищем top-1 catalog_item
 *     по cosine similarity. Если similarity >= threshold → возвращаем
 *     {catalog_item, similarity}, иначе null.
 *
 * Fail-soft: при любой ошибке OpenAI / pgvector — лог + возврат null.
 * Основной парсер/резолв не валим.
 */
class CatalogEmbeddingService
{
    public function __construct(
        private readonly OpenAIEmbeddingService $embedder,
        private readonly OpenAIChatService $chat,
    ) {
    }

    /**
     * Собрать text для embed по строке каталога. Поля null/пустые пропускаются.
     * Должно быть симметрично с buildQueryText (для RequestItem) — иначе
     * embed-пространства не совпадут и similarity будет плохой.
     */
    public function buildCatalogText(CatalogItem $item): string
    {
        $parts = [];
        if ($item->brand) {
            $parts[] = 'Бренд: ' . $item->brand;
        }
        if ($item->unit_name) {
            $parts[] = 'Узел: ' . $item->unit_name;
        }
        if ($item->part_type) {
            $parts[] = 'Тип запчасти: ' . $item->part_type;
        }
        if ($item->brand_article) {
            $parts[] = 'Артикул производителя: ' . $item->brand_article;
        }
        $parts[] = 'Название: ' . $item->name;
        if ($item->name_en) {
            $parts[] = 'Name (EN): ' . $item->name_en;
        }

        return implode("\n", $parts);
    }

    /**
     * Симметрично, но из RequestItem (с тем, что есть на руках после парсера).
     * Если бренд/категория резолвнулись через KB — используем их (это
     * качественнее, чем parsed_*).
     */
    public function buildQueryText(RequestItem $item): string
    {
        $parts = [];
        $brand = $item->brand?->name ?: $item->parsed_brand;
        if ($brand) {
            $parts[] = 'Бренд: ' . $brand;
        }
        $category = $item->kbCategory?->name;
        if ($category) {
            $parts[] = 'Тип запчасти: ' . $category;
        }
        if (! empty($item->parsed_article)) {
            $parts[] = 'Артикул производителя: ' . $item->parsed_article;
        }
        if (! empty($item->parsed_name)) {
            $parts[] = 'Название: ' . $item->parsed_name;
        }

        return implode("\n", $parts);
    }

    public function hashText(string $text): string
    {
        return hash('sha256', $text);
    }

    /**
     * Bulk-синк всех каталожных эмбеддингов. force=true → перегенерить даже
     * если source_hash совпадает (нужно после смены модели или формулы text).
     *
     * @return array{checked: int, synced: int, skipped: int, errors: int, tokens_used: int}
     */
    public function syncAll(bool $force = false): array
    {
        $stats = ['checked' => 0, 'synced' => 0, 'skipped' => 0, 'errors' => 0, 'tokens_used' => 0];
        $batchSize = (int) config('services.openai.embedding_batch_size', 100);
        $model = (string) config('services.openai.embedding_model', 'text-embedding-3-small');

        CatalogItem::query()
            ->where('is_active', true)
            ->chunkById(500, function ($items) use (&$stats, $batchSize, $model, $force) {
                $existingHashes = CatalogItemEmbedding::query()
                    ->whereIn('catalog_item_id', $items->pluck('id'))
                    ->pluck('source_hash', 'catalog_item_id')
                    ->all();

                $batchItems = []; // catalog_item -> text для отправки в OpenAI

                foreach ($items as $item) {
                    $stats['checked']++;
                    $text = $this->buildCatalogText($item);
                    $hash = $this->hashText($text);
                    $stored = $existingHashes[$item->id] ?? null;
                    if (! $force && $stored === $hash) {
                        $stats['skipped']++;
                        continue;
                    }
                    $batchItems[] = ['item' => $item, 'text' => $text, 'hash' => $hash];
                    if (count($batchItems) >= $batchSize) {
                        $this->flushBatch($batchItems, $model, $stats);
                        $batchItems = [];
                    }
                }

                if (! empty($batchItems)) {
                    $this->flushBatch($batchItems, $model, $stats);
                }
            });

        Log::info('CatalogEmbeddingService: syncAll done', $stats);

        return $stats;
    }

    /**
     * Топ-1 catalog_item по cosine similarity к query embedding'у позиции
     * заявки. Брендовая страховка: если у RequestItem и Catalog оба бренда
     * заполнены, и они отличаются — не возвращаем (false-positive risk).
     *
     * Возвращает массив с найденной катало-сущностью и similarity, либо null.
     *
     * @return array{catalog: CatalogItem, similarity: float}|null
     */
    public function matchByRequestItem(RequestItem $item, ?float $thresholdOverride = null): ?array
    {
        $threshold = $thresholdOverride ?? (float) config('services.catalog_name_match.threshold', 0.75);
        $queryText = $this->buildQueryText($item);
        if (mb_strlen(trim($queryText)) < 5) {
            return null;
        }

        try {
            $result = $this->embedder->embed($queryText);
        } catch (\Throwable $e) {
            Log::warning('CatalogEmbeddingService: query embed failed', [
                'request_item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
        $queryVector = $result['embedding'] ?? [];
        if (empty($queryVector)) {
            return null;
        }

        // Cosine similarity = 1 - cosine_distance. pgvector `<=>` это
        // cosine_distance. similarity = 1 - distance.
        $vectorLiteral = '[' . implode(',', array_map(
            fn ($v) => is_finite($v) ? sprintf('%.7f', $v) : '0',
            $queryVector
        )) . ']';

        $row = DB::selectOne(
            <<<'SQL'
            SELECT ci.id AS catalog_id,
                   1 - (e.embedding <=> ?::vector) AS similarity
            FROM catalog_item_embeddings e
            JOIN catalog_items ci ON ci.id = e.catalog_item_id
            WHERE ci.is_active = true
            ORDER BY e.embedding <=> ?::vector
            LIMIT 1
            SQL,
            [$vectorLiteral, $vectorLiteral],
        );

        if ($row === null) {
            return null;
        }
        $similarity = (float) $row->similarity;
        if ($similarity < $threshold) {
            return null;
        }

        $catalog = CatalogItem::find($row->catalog_id);
        if ($catalog === null) {
            return null;
        }

        // Бренд safety-check.
        $itemBrand = $this->normalizeBrand($item->brand?->name ?: $item->parsed_brand);
        $catalogBrand = $this->normalizeBrand($catalog->brand);
        if ($itemBrand !== '' && $catalogBrand !== '' && $itemBrand !== $catalogBrand) {
            Log::info('CatalogEmbeddingService: brand mismatch — match rejected', [
                'request_item_id' => $item->id,
                'item_brand' => $itemBrand,
                'catalog_brand' => $catalogBrand,
                'catalog_id' => $catalog->id,
                'similarity' => $similarity,
            ]);

            return null;
        }

        // Article safety-check (two-stage retrieval, second stage):
        // если у обоих filled артикулы и они нормализованно различаются —
        // это разные товары (SC-E2/G vs SC-E02, 22214 vs 22220, MS7001.1 vs MS2),
        // даже если name семантически близкое. Reject.
        if (! $this->isArticleSafe($item->parsed_article, $catalog->brand_article)) {
            Log::info('CatalogEmbeddingService: article mismatch — match rejected', [
                'request_item_id' => $item->id,
                'item_article' => $item->parsed_article,
                'catalog_brand_article' => $catalog->brand_article,
                'catalog_id' => $catalog->id,
                'similarity' => $similarity,
            ]);

            return null;
        }

        // Third stage: LLM-валидация для пар с similarity ниже high-confidence
        // порога. Mini-модель отвечает «one and the same? Y/N» с reasoning.
        // Это убивает «звено цепи vs звезда», «поручень vs ролик», разные
        // модификации в одной серии — кейсы, где vector ловит общий контекст,
        // но это РАЗНЫЕ товары.
        $hcThreshold = (float) config('services.catalog_name_match.hc_threshold', 0.90);
        $llmEnabled = (bool) config('services.catalog_name_match.llm_validation_enabled', true);
        $failAction = (string) config('services.catalog_name_match.llm_fail_action', 'reject');
        $llmDecision = null;
        if ($llmEnabled && $similarity < $hcThreshold) {
            // Внешний retry поверх Http::retry — на случай, если прокси-сервер
            // (api.openai-proxy) отдаёт 503 серией (мы наблюдали это в bulk-pass'е).
            // Стандартный Laravel-retry укладывается в ~6 сек; если outage
            // длиннее — внешняя пауза в 5с даёт прокси время восстановиться.
            $attempts = 2;
            for ($attempt = 0; $attempt < $attempts; $attempt++) {
                $llmDecision = $this->validateMatchWithLlm($item, $catalog, $similarity);
                if ($llmDecision !== null) {
                    break;
                }
                if ($attempt + 1 < $attempts) {
                    sleep(5);
                }
            }

            if ($llmDecision !== null && $llmDecision['same'] === false) {
                Log::info('CatalogEmbeddingService: LLM rejected match', [
                    'request_item_id' => $item->id,
                    'catalog_id' => $catalog->id,
                    'catalog_sku' => $catalog->sku,
                    'similarity' => $similarity,
                    'reason' => $llmDecision['reason'] ?? null,
                ]);

                return null;
            }
            // LLM упал после всех retry → действуем по config:
            //   llm_fail_action=reject (default) — отклоняем match, vector
            //                                       без подтверждения LLM не доверяем;
            //   llm_fail_action=accept            — принимаем (старое поведение).
            if ($llmDecision === null && $failAction === 'reject') {
                Log::info('CatalogEmbeddingService: LLM failed — match rejected by llm_fail_action=reject', [
                    'request_item_id' => $item->id,
                    'catalog_id' => $catalog->id,
                    'catalog_sku' => $catalog->sku,
                    'similarity' => $similarity,
                ]);

                return null;
            }
            // null + accept → продолжаем, помечаем skipped_llm_failed.
        }

        return [
            'catalog' => $catalog,
            'similarity' => $similarity,
            'llm_validation' => $llmDecision === null
                ? ($similarity >= $hcThreshold ? 'skipped_high_confidence' : 'skipped_llm_failed')
                : 'approved',
            'llm_reason' => $llmDecision['reason'] ?? null,
        ];
    }

    /**
     * Бинарная LLM-валидация. Возвращает {same, reason} или null при сбое.
     *
     * @return array{same: bool, reason: string}|null
     */
    public function validateMatchWithLlm(RequestItem $item, CatalogItem $catalog, float $similarity): ?array
    {
        try {
            $result = $this->chat->chat(
                [
                    ['role' => 'system', 'content' => ValidateCatalogMatchPrompt::systemMessage()],
                    ['role' => 'user', 'content' => ValidateCatalogMatchPrompt::userMessage(
                        $item->brand?->name ?: $item->parsed_brand,
                        $item->parsed_name,
                        $item->parsed_article,
                        $catalog->brand,
                        $catalog->name,
                        $catalog->brand_article,
                        (string) $catalog->sku,
                        $similarity,
                    )],
                ],
                config('services.openai.clarification_model', 'gpt-4o-mini'),
                ['response_format' => ['type' => 'json_object'], 'temperature' => 0, 'max_tokens' => 200],
            );
        } catch (\Throwable $e) {
            Log::warning('CatalogEmbeddingService: LLM validation call failed (non-fatal)', [
                'request_item_id' => $item->id,
                'catalog_id' => $catalog->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $parsed = json_decode((string) ($result['content'] ?? ''), true);
        if (! is_array($parsed) || ! array_key_exists('same', $parsed)) {
            Log::warning('CatalogEmbeddingService: LLM returned malformed response', [
                'request_item_id' => $item->id,
                'content' => mb_substr((string) ($result['content'] ?? ''), 0, 200),
            ]);

            return null;
        }

        return [
            'same' => (bool) $parsed['same'],
            'reason' => is_string($parsed['reason'] ?? null) ? mb_substr($parsed['reason'], 0, 500) : '',
        ];
    }

    /**
     * Постфильтр для C-step: если у обоих (RequestItem и CatalogItem) есть
     * непустой артикул и нормализованные формы НЕ совпадают ни по одному
     * comma-split-токену — match отклоняем.
     *
     * Если у одной стороны (или обеих) артикул пустой — пропускаем, не
     * блокируем (C-step как раз для таких — name-only matching).
     */
    public function isArticleSafe(?string $itemArticle, ?string $catalogArticle): bool
    {
        $catalogNorm = CatalogImportService::normalizeArticle($catalogArticle);
        if ($catalogNorm === null || $catalogNorm === '') {
            return true;
        }
        if ($itemArticle === null || trim($itemArticle) === '') {
            return true;
        }

        // parsed_article может содержать "GAA638JR1, 3RT2016-2GG22" — тот же
        // split что в matchByArticle. Если хоть один токен совпадает с
        // catalog.brand_article — пропускаем (значит B-step тоже бы сматчил,
        // и мы согласны с этим).
        $tokens = preg_split('/\s*[,\/]\s*/', $itemArticle) ?: [$itemArticle];
        foreach ($tokens as $tok) {
            $norm = CatalogImportService::normalizeArticle($tok);
            if ($norm !== null && $norm !== '' && $norm === $catalogNorm) {
                return true;
            }
        }

        return false;
    }

    /**
     * Отправить батч в OpenAI и записать результаты в БД одной транзакцией.
     *
     * @param  list<array{item: CatalogItem, text: string, hash: string}>  $batch
     */
    private function flushBatch(array $batch, string $model, array &$stats): void
    {
        $texts = array_map(fn ($b) => $b['text'], $batch);

        try {
            $result = $this->embedder->embedBatch($texts, $model);
        } catch (\Throwable $e) {
            Log::error('CatalogEmbeddingService: batch embed failed', [
                'batch_size' => count($batch),
                'error' => $e->getMessage(),
            ]);
            $stats['errors'] += count($batch);

            return;
        }

        $stats['tokens_used'] += (int) ($result['usage']['total_tokens'] ?? 0);
        $embeddings = $result['embeddings'] ?? [];
        if (count($embeddings) !== count($batch)) {
            Log::error('CatalogEmbeddingService: batch size mismatch', [
                'sent' => count($batch),
                'received' => count($embeddings),
            ]);
            $stats['errors'] += count($batch);

            return;
        }

        $now = Carbon::now();
        DB::transaction(function () use ($batch, $embeddings, $model, $now, &$stats) {
            foreach ($batch as $i => $b) {
                $vec = '[' . implode(',', array_map(
                    fn ($v) => is_finite($v) ? sprintf('%.7f', $v) : '0',
                    $embeddings[$i]
                )) . ']';

                // upsert через ON CONFLICT (catalog_item_id) — у нас unique.
                DB::statement(
                    <<<'SQL'
                    INSERT INTO catalog_item_embeddings
                        (catalog_item_id, source_hash, source_text, model_version, embedding, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?::vector, ?, ?)
                    ON CONFLICT (catalog_item_id) DO UPDATE
                       SET source_hash = EXCLUDED.source_hash,
                           source_text = EXCLUDED.source_text,
                           model_version = EXCLUDED.model_version,
                           embedding = EXCLUDED.embedding,
                           updated_at = EXCLUDED.updated_at
                    SQL,
                    [
                        $b['item']->id,
                        $b['hash'],
                        $b['text'],
                        $model,
                        $vec,
                        $now,
                        $now,
                    ],
                );
                $stats['synced']++;
            }
        });
    }

    private function normalizeBrand(?string $b): string
    {
        if ($b === null) {
            return '';
        }
        $s = mb_strtoupper(trim($b));

        // Защита от мелких различий между «Schneider Electric» и «Schneider».
        // Берём первое слово, без знаков пунктуации. На больших каталогах
        // может сливать разные бренды с общим словом (например, «GENERAL
        // Electric» vs «GENERAL Motors»), но для текущего домена лифтового
        // оборудования это редкая ситуация.
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        $first = explode(' ', trim((string) $s))[0] ?? '';

        return $first;
    }
}
