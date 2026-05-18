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
    /**
     * Top-N похожих позиций каталога к данной RequestItem — для UI-просмотра
     * оператором («Похожие из каталога» в ItemCatalogLinkDialog).
     *
     * Без threshold/safety-фильтров: возвращаем как есть с similarity,
     * оператор сам решает что выбрать. Запросов к LLM не делаем — это
     * preview, а не auto-match.
     *
     * @return array<int, array{catalog: CatalogItem, similarity: float}>
     */
    public function topNByRequestItem(RequestItem $item, int $n = 10): array
    {
        $queryText = $this->buildQueryText($item);
        return $this->topNByQueryText($queryText, $n, $item->id);
    }

    /**
     * Top-N похожих позиций каталога по произвольному тексту запроса —
     * используется в UI «Похожие из каталога», когда менеджер вводит
     * свой запрос (напр. «Плата ПКЛ-32») вместо опоры на parsed_name.
     *
     * Без threshold/safety-фильтров: возвращаем все, что нашлось, оператор
     * сам выбирает. requestItemId — только для логов (можно null).
     *
     * @return array<int, array{catalog: CatalogItem, similarity: float}>
     */
    public function topNByQueryText(string $queryText, int $n = 10, ?int $requestItemId = null): array
    {
        $queryText = trim($queryText);
        if (mb_strlen($queryText) < 2) {
            return [];
        }

        $limit = max(1, min($n, 50));
        $poolLimit = max($limit, 20);

        // 1) Code-token ILIKE — извлекаем из запроса токены вида ПКЛ32 /
        //    ЕИЛА.687255.008-04 (буквы+цифры, ≥3 симв.) и ищем их как
        //    подстроки в normalized name, brand_article_normalized и
        //    articles[]. Это решает кейс «Плата ПКЛ-32»: word_similarity
        //    рассеивается на длинных фразах, ILIKE же ловит «ПКЛ32» прямо.
        $codeRows = $this->codeTokenTopN($queryText, $poolLimit);

        // 2) Trigram (pg_trgm) — для fuzzy-матча целой фразы.
        $trgmRows = $this->trigramTopN($queryText, $poolLimit);

        // 3) Vector — семантика. embed-вызов в OpenAI ~500-2000мс. Дёргаем
        //    только если faster-источники не дали достаточно результатов —
        //    типичный кейс «Плата ПКЛ-32» / «Башмак кабины OTIS» закрывается
        //    code+trgm без vector. Это срезает 1-2 секунды с UI-отклика.
        $fastEnough = (count($codeRows) + count($trgmRows)) >= $limit;
        $vectorRows = $fastEnough ? [] : $this->vectorTopN($queryText, $poolLimit, $requestItemId);

        if ($codeRows === [] && $trgmRows === [] && $vectorRows === []) {
            return [];
        }

        // 4) Merge по catalog_id. Score = MAX:
        //    - code = 0.95 (твёрдое substring-вхождение токена в name/article);
        //    - trgm * 1.10 (точное word-extent matching);
        //    - vector (семантика).
        //    Method для UI — лучший источник; если несколько → 'multi'.
        $merged = [];
        foreach ($codeRows as $r) {
            $cid = (int) $r['catalog_id'];
            $merged[$cid] = ['catalog_id' => $cid, 'code' => (float) $r['similarity'], 'trgm' => null, 'vector' => null];
        }
        foreach ($trgmRows as $r) {
            $cid = (int) $r['catalog_id'];
            $merged[$cid] ??= ['catalog_id' => $cid, 'code' => null, 'trgm' => null, 'vector' => null];
            $merged[$cid]['trgm'] = (float) $r['similarity'];
        }
        foreach ($vectorRows as $r) {
            $cid = (int) $r['catalog_id'];
            $merged[$cid] ??= ['catalog_id' => $cid, 'code' => null, 'trgm' => null, 'vector' => null];
            $merged[$cid]['vector'] = (float) $r['similarity'];
        }

        $scored = [];
        foreach ($merged as $cid => $m) {
            $code = $m['code'];
            $trgm = $m['trgm'];
            $vec = $m['vector'];

            $candidates = [];
            if ($code !== null) $candidates['code'] = $code;
            if ($trgm !== null) $candidates['trgm'] = $trgm * 1.10;
            if ($vec !== null) $candidates['vector'] = (float) $vec;
            $bestSource = array_keys($candidates, max($candidates), true)[0];
            $score = max(0.0, min(1.0, max($candidates)));

            // Tiebreaker: +0.001 за каждый дополнительный источник, чтобы
            // multi-source поднимался над одинаково оценённым single-source.
            $score += 0.001 * (count($candidates) - 1);
            $score = min(1.0, $score);

            $method = count($candidates) >= 2 ? 'multi' : $bestSource;

            $scored[] = [
                'catalog_id' => $cid,
                'score' => $score,
                'method' => $method,
                'code' => $code,
                'trgm' => $trgm,
                'vector' => $vec,
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, $limit);

        $ids = array_map(fn ($r) => $r['catalog_id'], $scored);
        $catalogs = CatalogItem::query()->whereIn('id', $ids)->get()->keyBy('id');

        $out = [];
        foreach ($scored as $r) {
            $cat = $catalogs->get($r['catalog_id']);
            if ($cat === null) {
                continue;
            }
            $out[] = [
                'catalog' => $cat,
                'similarity' => $r['score'],
                'method' => $r['method'], // code | trgm | vector | multi
                'code_score' => $r['code'],
                'trgm_score' => $r['trgm'],
                'vector_score' => $r['vector'],
            ];
        }

        return $out;
    }

    /**
     * Извлечь из произвольной строки токены вида ПКЛ32, ЕИЛА.687255.008-04 —
     * последовательности символов с минимум одной буквой И одной цифрой,
     * длина ≥3 после очистки разделителей. Используется для прямого
     * ILIKE-substring-поиска (см. codeTokenTopN).
     *
     * Дублируется нормализация (uppercase + strip [\s\-_./]) — совместима
     * с тем как импортируется brand_article_normalized и как мы потом
     * нормализуем articles[] элементы в SQL.
     *
     * @return list<string>
     */
    private function extractCodeTokens(string $queryText): array
    {
        $tokens = preg_split('/[\s,;]+/u', $queryText) ?: [];
        $out = [];
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if (mb_strlen($tok) < 3) continue;
            // Должен иметь и буквы, и цифры — иначе «Плата» / «32» пройдут
            // и зашумят результаты.
            if (! preg_match('/\p{L}/u', $tok)) continue;
            if (! preg_match('/\d/u', $tok)) continue;
            $norm = preg_replace('/[\s\-_.\/]/u', '', mb_strtoupper($tok)) ?? '';
            if (mb_strlen($norm) >= 3 && ! in_array($norm, $out, true)) {
                $out[] = $norm;
            }
        }
        return $out;
    }

    /**
     * ILIKE substring match по нормализованным name/brand_article/articles[]
     * для каждого code-token. Возвращает все catalog_items, где хотя бы один
     * токен встречается. Score = 0.95 (твёрдое substring-вхождение).
     *
     * Дешевле trigram'а на коротких токенах, но менее устойчив к опечаткам.
     * Trigram остаётся для fuzzy-кейсов.
     *
     * @return list<array{catalog_id: int, similarity: float}>
     */
    private function codeTokenTopN(string $queryText, int $limit): array
    {
        $tokens = $this->extractCodeTokens($queryText);
        if ($tokens === []) {
            return [];
        }

        // Два варианта токенов: lower (для lower(name)/lower(article) индексов)
        // и uppercase (для brand_article_normalized и articles[]).
        // PG-array literals: '{"%tok1%","%tok2%"}'.
        $mkPgArr = function (array $toks) {
            $like = array_map(
                fn ($t) => '%' . str_replace(['\\', '"'], ['\\\\', '\\"'], $t) . '%',
                $toks,
            );
            return '{' . implode(',', array_map(fn ($s) => '"' . $s . '"', $like)) . '}';
        };
        $upperArr = $mkPgArr($tokens);
        $lowerArr = $mkPgArr(array_map(mb_strtolower(...), $tokens));

        try {
            // WHERE expression для name ТОЧНО совпадает с GIN trgm индексом
            // catalog_items_name_nosep_trgm_idx — критично для скорости.
            //
            // articles[] EXISTS (multi-OEM) ТУТ НЕ дёргается — это seq scan
            // c jsonb_array_elements_text на 35K rows ~500мс. Multi-OEM
            // покрывается trigramTopN, где articles[] <% $norm работает
            // для article-like запросов длиной ≥4 символов.
            $rows = DB::select(
                "
                SELECT id AS catalog_id
                FROM catalog_items
                WHERE is_active = true
                  AND (
                       regexp_replace(lower(name), '[\\s\\-_./]', '', 'g') ILIKE ANY (?::text[])
                       OR brand_article_normalized ILIKE ANY (?::text[])
                  )
                LIMIT ?
                ",
                [$lowerArr, $upperArr, $limit],
            );
        } catch (\Throwable $e) {
            Log::info('CatalogEmbeddingService: code-token search failed', [
                'error' => $e->getMessage(),
                'tokens' => $tokens,
            ]);
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = ['catalog_id' => (int) $r->catalog_id, 'similarity' => 0.95];
        }
        return $out;
    }

    /**
     * Trigram-поиск (pg_trgm) по lower(name) и brand_article_normalized.
     * Fail-soft если расширение/индексы не доступны — пустой массив.
     *
     * @return list<array{catalog_id: int, similarity: float}>
     */
    private function trigramTopN(string $queryText, int $limit): array
    {
        $lower = mb_strtolower($queryText);
        // Дефис/пробел-очищенный lower — для случая «Плата ПКЛ-32» против
        // «Плата контроллера лифта типа ПКЛ32-04»: word_similarity на raw
        // даёт ~0.1 из-за разных триграмм вокруг дефиса, на nosep — ~0.8.
        $lowerNoSep = (string) preg_replace('/[\s\-_.\/]/u', '', $lower);
        // normalizeArticle: uppercase + strip [\s\-_./]. Совместимо с тем
        // как мы импортируем brand_article_normalized в CatalogImportService.
        $norm = (string) (\App\Services\Catalog\CatalogImportService::normalizeArticle($queryText) ?? '');

        try {
            // 4 слота:
            //   1) word_similarity по raw lower(name) — «ПКЛ32-04» в «...ПКЛ32-04...»;
            //   2) word_similarity по dehyphenated lower(name) — «Плата ПКЛ-32»
            //      против «ПлатаПКЛ32»;
            //   3) similarity по brand_article_normalized — primary OEM;
            //   4) MAX similarity по любому элементу articles[] (jsonb) —
            //      multi-OEM позиции типа M16660 у которых нужный артикул
            //      сидит во втором/третьем элементе массива.
            //
            // similarity() default threshold 0.3, word_similarity() — 0.6.
            // GIN-индексы по lower(name), regexp_replace(lower(name),...),
            // brand_article_normalized ускоряют 1-3. Слот 4 — seq+jsonb_array
            // ~500мс на 35K, поэтому тригерим только когда query действительно
            // похож на артикул: ≥4 символа И ≥6 цифр в нормализованном виде.
            // Это отрезает «Плата ПКЛ-32» (всего 2 цифры) от articles[]-scan,
            // оставляя его только для «ЕИЛА.687255.008-04» и подобных.
            $digitCount = mb_strlen((string) preg_replace('/\D/u', '', $norm));
            $useArticles = $norm !== '' && mb_strlen($norm) >= 4 && $digitCount >= 6;

            // word_similarity ассиметрична: «находит первый аргумент как
            // подстроку во втором». Для слотов «артикул каталога vs
            // пользовательский запрос» — артикул короткий, запрос может
            // быть длинным (verbose «Плата управления ПКЛ-32 с ПЗУ
            // ЕИЛА.687255.008-04»). Обычный similarity('ЕИЛА687255008-04',
            // long_query) ≈ 0.32 (теряется в длине union). word_similarity
            // (catalog_article, user_query) находит подстроку и даёт ~1.0.
            //
            // Для name (длинная сторона target) — query короткая или
            // верхожая, word_similarity(query, name) → находит query в name.
            $sql = "
                SELECT id AS catalog_id,
                       GREATEST(
                           word_similarity(?, lower(name)),
                           word_similarity(?, regexp_replace(lower(name), '[\\s\\-_./]', '', 'g')),
                           CASE WHEN ? <> '' THEN word_similarity(brand_article_normalized, ?) ELSE 0 END
                           " . ($useArticles ? ",
                           COALESCE((
                               SELECT MAX(word_similarity(upper(regexp_replace(a, '[\\s\\-_./]', '', 'g')), ?))
                               FROM jsonb_array_elements_text(articles) AS a
                               WHERE a IS NOT NULL AND a <> ''
                           ), 0)" : "") . "
                       ) AS s
                FROM catalog_items
                WHERE is_active = true
                  AND (
                       ? <% lower(name)
                       OR ? <% regexp_replace(lower(name), '[\\s\\-_./]', '', 'g')
                       OR (? <> '' AND brand_article_normalized <% ?)
                       " . ($useArticles ? "
                       OR EXISTS (
                           SELECT 1 FROM jsonb_array_elements_text(articles) AS a
                           WHERE a IS NOT NULL AND a <> ''
                             AND upper(regexp_replace(a, '[\\s\\-_./]', '', 'g')) <% ?
                       )" : "") . "
                  )
                ORDER BY s DESC
                LIMIT ?
            ";

            $bindings = $useArticles
                ? [$lower, $lowerNoSep, $norm, $norm, $norm, $lower, $lowerNoSep, $norm, $norm, $norm, $limit]
                : [$lower, $lowerNoSep, $norm, $norm, $lower, $lowerNoSep, $norm, $norm, $limit];

            $rows = DB::select($sql, $bindings);
        } catch (\Throwable $e) {
            // pg_trgm нет / индексы не созданы / синтаксис не поддерживается —
            // тихо отдаём пустой массив, hybrid fallback'нется в pure vector.
            Log::info('CatalogEmbeddingService: trigram unavailable, vector-only fallback', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'catalog_id' => (int) $r->catalog_id,
                'similarity' => (float) $r->s,
            ];
        }
        return $out;
    }

    /**
     * Vector-поиск (старая ветка) — возвращает raw rows без модели.
     *
     * @return list<array{catalog_id: int, similarity: float}>
     */
    private function vectorTopN(string $queryText, int $limit, ?int $requestItemId): array
    {
        try {
            $result = $this->embedder->embed($queryText);
        } catch (\Throwable $e) {
            Log::warning('CatalogEmbeddingService: topN embed failed', [
                'request_item_id' => $requestItemId,
                'query_preview' => mb_substr($queryText, 0, 80),
                'error' => $e->getMessage(),
            ]);
            return [];
        }
        $queryVector = $result['embedding'] ?? [];
        if (empty($queryVector)) {
            return [];
        }

        $vectorLiteral = '[' . implode(',', array_map(
            fn ($v) => is_finite($v) ? sprintf('%.7f', $v) : '0',
            $queryVector
        )) . ']';

        try {
            $rows = DB::select(
                <<<'SQL'
                SELECT ci.id AS catalog_id,
                       1 - (e.embedding <=> ?::vector) AS similarity
                FROM catalog_item_embeddings e
                JOIN catalog_items ci ON ci.id = e.catalog_item_id
                WHERE ci.is_active = true
                ORDER BY e.embedding <=> ?::vector
                LIMIT ?
                SQL,
                [$vectorLiteral, $vectorLiteral, $limit],
            );
        } catch (\Throwable $e) {
            Log::warning('CatalogEmbeddingService: vector select failed', [
                'request_item_id' => $requestItemId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'catalog_id' => (int) $r->catalog_id,
                'similarity' => (float) $r->similarity,
            ];
        }
        return $out;
    }

    public function matchByRequestItem(RequestItem $item, ?float $thresholdOverride = null): ?array
    {
        $threshold = $thresholdOverride ?? (float) app_setting(
            'catalog.name_match.threshold',
            config('services.catalog_name_match.threshold', 0.75),
        );
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
        $hcThreshold = (float) app_setting(
            'catalog.name_match.hc_threshold',
            config('services.catalog_name_match.hc_threshold', 0.90),
        );
        $llmEnabled = (bool) app_setting(
            'catalog.name_match.llm_validation_enabled',
            config('services.catalog_name_match.llm_validation_enabled', true),
        );
        $failAction = (string) app_setting(
            'catalog.name_match.llm_fail_action',
            config('services.catalog_name_match.llm_fail_action', 'reject'),
        );
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
