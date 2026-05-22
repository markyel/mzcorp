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
        // Локальные коды поставщика (LW-..., см. LocalSupplierCodePattern)
        // — это НЕ настоящий OEM-артикул. В каталоге их нет (там лежит
        // оригинальный TAA*/GAA*/DAA*-код производителя). Если положить
        // LW-код в embed-текст, vector ловит шум на «LW0027349» и top-1
        // съезжает к случайно похожим товарам. Пропускаем такие токены.
        if (! empty($item->parsed_article)
            && ! LocalSupplierCodePattern::isAllLocal($item->parsed_article)) {
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
        // Code-token pool — расширенный (100 вместо 20). Запросы с
        // генерик-токенами «220VAC»/«380В»/«24VDC» (электрические юниты
        // встречаются в сотнях позиций) дают огромный matching set; LIMIT 20
        // без ORDER BY возвращал случайные 20 строк, искомый специфический
        // товар выпадал. С 100 покрываем практически все matching items
        // (≥100 совпадений на одном генерик-токене редко), backfill
        // подтянет vec для них и tie-break выберет правильного top-1.
        // Кейс #2385: M28598 (CENTA фотобарьер) не попадал ни в code-20,
        // ни в vector-20 — с code-100 точно попадёт.
        $codePoolLimit = max($poolLimit, 100);

        // Timing-логи: считаем длительность каждого шага отдельно
        // (code/trgm/vector/backfill/total). Без них непонятно где
        // бутылочное горлышко: embed-запрос к OpenAI обычно 500-2000мс,
        // но иногда тормозит SQL backfill или сам vectorTopN.
        $tStart = microtime(true);

        // 1) Code-token ILIKE — извлекаем из запроса токены вида ПКЛ32 /
        //    ЕИЛА.687255.008-04 (буквы+цифры, ≥3 симв.) и ищем их как
        //    подстроки в normalized name, brand_article_normalized и
        //    articles[]. Это решает кейс «Плата ПКЛ-32»: word_similarity
        //    рассеивается на длинных фразах, ILIKE же ловит «ПКЛ32» прямо.
        $tCodeStart = microtime(true);
        $codeRows = $this->codeTokenTopN($queryText, $codePoolLimit);
        $tCodeMs = (int) ((microtime(true) - $tCodeStart) * 1000);

        // 2) Trigram (pg_trgm) — для fuzzy-матча целой фразы.
        // 3) Vector — семантика, ~500-2000мс embed.
        //
        // Раньше скипали trgm+vector если code давал ≥$limit результатов
        // («fast enough»). Это создавало баг для запросов типа
        // «Комплект фотобарьера антенн CENTA DT42 C-PROFILE, L2000 мм»:
        // code-token «220VAC»/«L2000» захватывал 20+ позиций каталога,
        // ВСЕ с фиксированным score=0.95 (hardcoded), pool заполнен,
        // trgm и vector НЕ дёргались вообще — usort при tied score
        // возвращал случайный top-1, искомый «Комплект фотобарьера»
        // оказывался не на 1 месте.
        // Решение: ВСЕГДА запускаем trgm+vector — они дают semantic
        // ранжирование, разруливающее ties между code-кандидатами.
        // Расходы: vector embed ~$0.0001 + 500-1500мс на запрос.
        $tTrgmStart = microtime(true);
        $trgmRows = $this->trigramTopN($queryText, $poolLimit);
        $tTrgmMs = (int) ((microtime(true) - $tTrgmStart) * 1000);

        $tVecStart = microtime(true);
        $vectorRows = $this->vectorTopN($queryText, $poolLimit, $requestItemId);
        $tVecMs = (int) ((microtime(true) - $tVecStart) * 1000);

        if ($codeRows === [] && $trgmRows === [] && $vectorRows === []) {
            Log::info('catalog.topN: empty (no candidates)', [
                'request_item_id' => $requestItemId,
                'query_preview' => mb_substr($queryText, 0, 80),
                't_code_ms' => $tCodeMs,
                't_trgm_ms' => $tTrgmMs,
                't_vec_ms' => $tVecMs,
                't_total_ms' => (int) ((microtime(true) - $tStart) * 1000),
            ]);
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

        // Vector backfill: items в code/trgm пуле, не попавшие в vector top-N
        // (vectorTopN LIMIT 20 — на запросах с генерик-токенами «220VAC»/
        // «380В»/«24VDC» сотни близких товаров, искомый может выпасть из
        // top-20). Догружаем их vector_score одним SQL-запросом по конкретным
        // id, чтобы они стали multi-source (code+vec) и корректно тиебрейкнулись
        // по семантическому сигналу.
        // Кейс #2385: CENTA-фотобарьер M28598 имел code=0.95+vec=0.879 (если
        // бы vec был доступен), но vec=— → blended 0.95 → проигрывал WECO
        // (multi, blended 1.0). С backfill: M28598 multi 1.0, vector tiebreak
        // даёт ему 1 место.
        $missingVecIds = [];
        foreach ($merged as $cid => $m) {
            if ($m['vector'] === null && ($m['code'] !== null || $m['trgm'] !== null)) {
                $missingVecIds[] = $cid;
            }
        }
        // Cap backfill: чем меньше id'шников в IN-условии pgvector backfill,
        // тем быстрее запрос. Сейчас (M-2026-1215, generic-токены 220VAC/L2000)
        // pool кандидатов из codeTokenTopN — до 100 элементов. SQL JOIN на
        // 100×35K rows может занимать 200-500мс, при том что для top-10
        // итогового скоринга нам нужны только лучшие кандидаты по
        // не-vector сигналам. Сжимаем до top-30: остальные всё равно
        // отсеятся min_vector_only фильтром или сортировкой.
        $backfillCap = (int) config('services.catalog.search.backfill_cap', 30);
        if (count($missingVecIds) > $backfillCap) {
            // Сортируем по max(code, trgm*1.10) desc, берём top-N.
            usort($missingVecIds, function ($a, $b) use ($merged) {
                $sa = max($merged[$a]['code'] ?? 0, ($merged[$a]['trgm'] ?? 0) * 1.10);
                $sb = max($merged[$b]['code'] ?? 0, ($merged[$b]['trgm'] ?? 0) * 1.10);
                return $sb <=> $sa;
            });
            $missingVecIds = array_slice($missingVecIds, 0, $backfillCap);
        }
        $tBackfillStart = microtime(true);
        if (! empty($missingVecIds)) {
            $backfilled = $this->vectorScoresForIds($queryText, $missingVecIds, $requestItemId);
            foreach ($backfilled as $cid => $sim) {
                if (isset($merged[$cid])) {
                    $merged[$cid]['vector'] = $sim;
                }
            }
        }
        $tBackfillMs = (int) ((microtime(true) - $tBackfillStart) * 1000);

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

            // Multi-source бонус: подтверждение разными способами =
            // существенно надёжнее одиночного. +0.05 за каждый
            // дополнительный источник (multi/2 → +0.05, multi/3 → +0.10).
            // Это поднимает multi над «жёстким» code=0.95 single-source:
            // code=0.7 + trgm=0.88 + vec=0.85 (max 0.88, +0.10 = 0.98)
            // встаёт выше «code-only 0.95».
            $score += 0.05 * (count($candidates) - 1);
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

        // Многоуровневая сортировка: главный ключ — blended score, но при
        // равных значениях (типично: оба capped на 1.0 после multi-source
        // bonus) разруливаем по семантическим сигналам — vector cosine,
        // затем trigram, затем catalog_id для детерминизма.
        //
        // Без этого PHP usort нестабильный для tied scores, и порядок
        // top-N зависит от случайного порядка строк из DB. Кейс #2385:
        // M28598 (искомый CENTA-фотобарьер, vec=0.88) и WECO M21626
        // (другой бренд, vec=0.73) оба имели blended=1.0 → искомый
        // случайно проигрывал и не попадал в top-10.
        // Фильтр шума: vector-only позиции с низким cosine — это семантические
        // false-positives (запрос «барабан» → vector цепляет «башмак» 0.3
        // потому что оба «лифтовые детали»). Отрезаем такие.
        //   · multi-source ИЛИ с кодом/trgm → пропускаем без фильтра
        //   · vector-only → требуем score ≥ min_vector_only (default 0.50)
        // Конфиг: config('services.catalog.search.min_vector_only', 0.50)
        $minVecOnly = (float) config('services.catalog.search.min_vector_only', 0.50);
        $scored = array_values(array_filter($scored, function ($r) use ($minVecOnly) {
            $isVectorOnly = $r['code'] === null && $r['trgm'] === null;
            if (! $isVectorOnly) {
                return true;
            }
            return ($r['vector'] ?? 0.0) >= $minVecOnly;
        }));

        usort($scored, function ($a, $b) {
            $cmp = $b['score'] <=> $a['score'];
            if ($cmp !== 0) {
                return $cmp;
            }
            $av = $a['vector'] ?? 0.0;
            $bv = $b['vector'] ?? 0.0;
            $cmp = $bv <=> $av;
            if ($cmp !== 0) {
                return $cmp;
            }
            $at = $a['trgm'] ?? 0.0;
            $bt = $b['trgm'] ?? 0.0;
            $cmp = $bt <=> $at;
            if ($cmp !== 0) {
                return $cmp;
            }
            return $a['catalog_id'] <=> $b['catalog_id'];
        });
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

        Log::info('catalog.topN: done', [
            'request_item_id' => $requestItemId,
            'query_preview' => mb_substr($queryText, 0, 80),
            'pool_code' => count($codeRows),
            'pool_trgm' => count($trgmRows),
            'pool_vec' => count($vectorRows),
            'backfilled' => count($missingVecIds),
            'returned' => count($out),
            't_code_ms' => $tCodeMs,
            't_trgm_ms' => $tTrgmMs,
            't_vec_ms' => $tVecMs,
            't_backfill_ms' => $tBackfillMs,
            't_total_ms' => (int) ((microtime(true) - $tStart) * 1000),
        ]);

        return $out;
    }

    /**
     * Разбить запрос на токены для per-token trigram поиска.
     *
     * Каждый токен:
     *   · lowercase
     *   · стрипнуты [\s\-_./,] (соответствует name-нормализации в индексе)
     *   · длина ≥3 (короткие — шум)
     *
     * Для русских слов ≥4 букв добавляем stem-вариант (отрезано окончание):
     *   «цепь» → «цеп», «ролика» → «ролик».
     *
     * @return list<string>
     */
    private function extractTrigramTokens(string $queryText): array
    {
        $parts = preg_split('/[\s,;]+/u', mb_strtolower($queryText)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $norm = (string) preg_replace('/[\s\-_.\/,]/u', '', $p);
            if (mb_strlen($norm) < 3) {
                continue;
            }
            $out[] = $norm;
            // Если чисто-русское слово ≥4 букв — добавляем stem-форму.
            if (mb_strlen($norm) >= 4 && preg_match('/^[а-яё]+$/u', $norm)) {
                $stem = $this->stemRussianWord($norm);
                if ($stem !== $norm && mb_strlen($stem) >= 3) {
                    $out[] = $stem;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Простое стемирование русских слов: отрезает типичные окончания
     * существительных/прилагательных. Не лингвистически идеально, но
     * закрывает 90% морфологических казусов trigram-поиска
     * («цепь»/«цепи»/«цепью» → «цеп»).
     *
     * Применяется к словам ≥4 букв. Минимальная длина основы — 3 буквы,
     * чтобы не превратить «дом» → «д». Окончания в порядке убывания длины,
     * чтобы «ями» сработало раньше «и».
     */
    private function stemRussianWord(string $word): string
    {
        if (mb_strlen($word) < 4) {
            return $word;
        }
        // Только русскоязычные слова — латиницу/цифры не трогаем.
        if (! preg_match('/^[а-яё]+$/u', $word)) {
            return $word;
        }
        // Окончания существительных, прилагательных, глаголов (упрощённо).
        static $endings = [
            'ыми', 'ями', 'ями', 'ями', 'ого', 'ому', 'его', 'ему',
            'ах', 'ям', 'ыми', 'ой', 'ою', 'ей', 'ью', 'ом', 'ям',
            'ев', 'ов', 'ам', 'ах', 'ат', 'ят',
            'ы', 'и', 'у', 'я', 'е', 'й', 'а', 'о', 'ь', 'ю',
        ];
        foreach ($endings as $end) {
            if (mb_strlen($word) - mb_strlen($end) >= 3 && str_ends_with($word, $end)) {
                return mb_substr($word, 0, -mb_strlen($end));
            }
        }
        return $word;
    }

    /**
     * Стемировать каждое слово в lower-фразе (после удаления разделителей
     * единым строковым символом склейки). Сохраняет латиницу/цифры
     * (артикулы T-135 → t135 не трогаются).
     */
    private function stemRussianPhrase(string $lowerNoSep): string
    {
        // Разрезаем lowerNoSep на «слова» = последовательности букв/цифр
        // или одиночные символы (не используется для articles, поэтому без
        // разделителей вход уже идёт). Но если на вход пришло несколько
        // склеенных русских слов — обработаем как одну строку
        // word-by-word через regex split по латиница<->кириллица переходам.
        if (! preg_match('/[а-яё]/u', $lowerNoSep)) {
            return $lowerNoSep;
        }
        // Pre-split по переходам между алфавитами и цифрами — на случай
        // если вход уже склеен (типа "цепьt135").
        $parts = preg_split('/(?<=[а-яё])(?=[a-z0-9])|(?<=[a-z0-9])(?=[а-яё])/u', $lowerNoSep) ?: [];
        $out = '';
        foreach ($parts as $p) {
            if (preg_match('/^[а-яё]+$/u', $p)) {
                $out .= $this->stemRussianWord($p);
            } else {
                $out .= $p;
            }
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
            if (mb_strlen($tok) < 5) continue; // отсекает «5мм», «3м/с», «2кВт»
            // Должен иметь и буквы, и цифры — иначе «Плата» / «32» пройдут.
            if (! preg_match('/\p{L}/u', $tok)) continue;
            if (! preg_match('/\d/u', $tok)) continue;
            $norm = preg_replace('/[\s\-_.\/]/u', '', mb_strtoupper($tok)) ?? '';
            // Норма ≥5 симв. И минимум 2 цифры — отсекает короткие
            // размерности типа «5ММ» (3 симв.) и фразы с одной цифрой
            // («ВКЛ1» — false-positive под любые «1» в каталоге). Реальные
            // артикулы (M04557, ПКЛ32, ЕИЛА68725500804, 12067R1, GAA638JR1)
            // легко проходят.
            if (mb_strlen($norm) < 5) continue;
            $digitCount = mb_strlen((string) preg_replace('/\D/u', '', $norm));
            if ($digitCount < 2) continue;
            if (! in_array($norm, $out, true)) {
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

        // 2026-05-21: точные совпадения по нашему внутреннему SKU должны
        // быть в выдаче первыми. Раньше WHERE не включало поле sku — поиск
        // по «M02016» не находил соответствующую catalog-карточку, потому
        // что её sku лежит в `sku`, а не в name/article/articles_search.
        $exactSkus = array_values(array_filter($tokens, fn ($t) => preg_match('/^M\d{4,}$/i', $t) === 1));
        $exactSkusUpper = array_map(fn ($s) => mb_strtoupper($s), $exactSkus);

        $hits = []; // catalog_id => max similarity

        // Phase A — exact-sku-match (similarity=1.0). Дешевле всего:
        // sku имеет btree-индекс, точное равенство — instant.
        if (! empty($exactSkusUpper)) {
            try {
                $exactRows = DB::select(
                    "SELECT id AS catalog_id FROM catalog_items
                     WHERE is_active = true AND UPPER(sku) = ANY (?::text[])
                     LIMIT ?",
                    ['{' . implode(',', $exactSkusUpper) . '}', $limit],
                );
                foreach ($exactRows as $r) {
                    $hits[(int) $r->catalog_id] = 1.0;
                }
            } catch (\Throwable $e) {
                Log::info('CatalogEmbeddingService: exact-sku search failed', [
                    'error' => $e->getMessage(),
                    'tokens' => $exactSkusUpper,
                ]);
            }
        }

        try {
            // WHERE expressions точно совпадают с GIN trgm индексами:
            //   - catalog_items_name_nosep_trgm_idx — для name (lower nosep)
            //   - catalog_items_articles_search_trgm_idx — для articles_search
            //   - catalog_items_brands_search_trgm_idx — для brands_search
            // ILIKE ANY (array) идёт по индексу за десятки мс на 35K rows.
            //
            // 2026-05-21: добавлен lower(sku) ILIKE ANY — для частичного
            // совпадения внутреннего SKU (например «02016» найдёт M02016).
            // Exact-match по sku уже обработан выше с приоритетом 1.0;
            // тут только partial substring (0.95) если sku содержит токен.
            $rows = DB::select(
                "
                SELECT id AS catalog_id
                FROM catalog_items
                WHERE is_active = true
                  AND (
                       lower(sku) ILIKE ANY (?::text[])
                       OR regexp_replace(lower(name), '[\\-_./,]', '', 'g') ILIKE ANY (?::text[])
                       OR brand_article_normalized ILIKE ANY (?::text[])
                       OR (articles_search IS NOT NULL AND articles_search ILIKE ANY (?::text[]))
                       OR (brands_search IS NOT NULL AND brands_search ILIKE ANY (?::text[]))
                  )
                LIMIT ?
                ",
                [$lowerArr, $lowerArr, $upperArr, $upperArr, $upperArr, $limit],
            );
            foreach ($rows as $r) {
                $catId = (int) $r->catalog_id;
                // Не понижаем оценку exact-sku-match'ам.
                if (! isset($hits[$catId])) {
                    $hits[$catId] = 0.95;
                }
            }
        } catch (\Throwable $e) {
            Log::info('CatalogEmbeddingService: code-token search failed', [
                'error' => $e->getMessage(),
                'tokens' => $tokens,
            ]);
        }

        if ($hits === []) {
            return [];
        }

        // Сортируем по similarity DESC, чтобы exact-match шёл первым.
        arsort($hits);
        $out = [];
        foreach ($hits as $catId => $score) {
            $out[] = ['catalog_id' => $catId, 'similarity' => $score];
            if (count($out) >= $limit) {
                break;
            }
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
        $lowerNoSep = (string) preg_replace('/[\s\-_.\/,]/u', '', $lower);
        // 2026-05-21: токенизация. Раньше word_similarity дёргался на
        // конкатенированной строке вида «цепьt135l1197» против длинного
        // catalog name. Когда токены разнесены в name (между «цеп» и
        // «l1197» 30+ символов мусора), extent растягивается, similarity
        // падает до 0.2 — ниже порога. Лекарство: разрезаем query на
        // токены, считаем word_similarity для каждого ОТДЕЛЬНО, берём MAX.
        // Тогда «t135» в name = 1.0, «l1197» = 1.0, «цеп» (stem) ~0.8.
        $tokens = $this->extractTrigramTokens($queryText);
        $lowerStemmed = $this->stemRussianPhrase($lowerNoSep);
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
            // похож на артикул: ≥4 символа И ≥5 цифр в нормализованном виде.
            // Это отрезает «Плата ПКЛ-32» (всего 2 цифры) от articles[]-scan,
            // оставляя его для «ЕИЛА.687255.008-04», «F0380CP3» (Otis-OEM с
            // 5 цифрами), «LW-0001163» (7 цифр) и подобных.
            $digitCount = mb_strlen((string) preg_replace('/\D/u', '', $norm));
            $useArticles = $norm !== '' && mb_strlen($norm) >= 4 && $digitCount >= 5;

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
            // Минимум слотов: dehyphenated name + (опционально) articles_search.
            // raw lower(name) и brand_article_normalized дублируют — code-token
            // уже их покрывает через ILIKE.
            //
            // articles_search — денормализованный text-столбец (миграция
            // 2026_05_18_160000_add_articles_search_column_to_catalog_items)
            // с GIN trgm индексом, обновляемый PG-триггером. Заменяет
            // jsonb_array_elements_text seq scan (~1500мс) на индексный
            // word_similarity lookup (~десятки мс).
            // Если токенов нет (запрос слишком короткий / только разделители) —
            // fallback на старый whole-string подход.
            if ($tokens === []) {
                $tokens = [$lowerNoSep];
            }

            // Per-token similarity. AVG по токенам — позиция с большим числом
            // совпавших токенов ранжируется выше. Для каждого токена берём
            // GREATEST(name_sim, articles_sim) — токен может совпасть с любым
            // из полей. WHERE — OR с literal-токенами (не EXISTS UNNEST),
            // чтобы PG использовал GIN trgm индекс (см. 2026-05-21:
            // EXISTS UNNEST давал nested loop вместо index lookup → 1.6с
            // вместо ~200мс).
            $nameExpr = "regexp_replace(lower(name), '[\\-_./,]', '', 'g')";
            $selectTerms = [];
            $whereOrs = [];
            $bindings = [];
            foreach ($tokens as $tok) {
                if ($useArticles) {
                    $selectTerms[] = "GREATEST("
                        . "word_similarity(?, $nameExpr), "
                        . "CASE WHEN articles_search IS NOT NULL AND articles_search <> '' "
                        . "THEN word_similarity(?, articles_search) ELSE 0 END)";
                    $bindings[] = $tok;
                    $bindings[] = $tok;
                } else {
                    $selectTerms[] = "word_similarity(?, $nameExpr)";
                    $bindings[] = $tok;
                }
            }
            $avgExpr = '(' . implode(' + ', $selectTerms) . ') / ' . count($tokens) . '.0';

            foreach ($tokens as $tok) {
                $whereOrs[] = "? <% $nameExpr";
                $bindings[] = $tok;
                if ($useArticles) {
                    $whereOrs[] = "(articles_search IS NOT NULL AND ? <% articles_search)";
                    $bindings[] = $tok;
                }
            }

            $sql = "
                SELECT id AS catalog_id, ($avgExpr) AS s
                FROM catalog_items
                WHERE is_active = true
                  AND (" . implode(' OR ', $whereOrs) . ")
                ORDER BY s DESC
                LIMIT ?
            ";
            $bindings[] = $limit;

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
    /**
     * Embed query text → pgvector literal `[v1,v2,...]` или null при ошибке.
     *
     * Двухуровневый кеш:
     *  1) process-static — для одного PHP-запроса (если embed дёргается дважды).
     *  2) Cache::driver (Redis/file) на 7 дней — для разных Livewire-апдейтов
     *     по той же позиции / другим позициям с тем же query text. Embedding
     *     `text-embedding-3-small` детерминирован, кеш безопасен. Это убирает
     *     500-2000мс OpenAI HTTP-вызова при повторных открытиях окна.
     *
     * Killswitch: `services.catalog.search.embed_cache_enabled` (default true).
     */
    private function embedQueryToVectorLiteral(string $queryText, ?int $requestItemId): ?string
    {
        static $procCache = [];
        $hash = md5($queryText);
        if (array_key_exists($hash, $procCache)) {
            return $procCache[$hash];
        }

        $useCache = (bool) config('services.catalog.search.embed_cache_enabled', true);
        $cacheKey = 'catalog_embed:' . $hash;
        $ttl = (int) config('services.catalog.search.embed_cache_ttl', 7 * 86400);

        if ($useCache) {
            $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $procCache[$hash] = $cached;
            }
        }

        try {
            $result = $this->embedder->embed($queryText);
        } catch (\Throwable $e) {
            Log::warning('CatalogEmbeddingService: topN embed failed', [
                'request_item_id' => $requestItemId,
                'query_preview' => mb_substr($queryText, 0, 80),
                'error' => $e->getMessage(),
            ]);
            return $procCache[$hash] = null;
        }
        $queryVector = $result['embedding'] ?? [];
        if (empty($queryVector)) {
            return $procCache[$hash] = null;
        }
        $literal = '[' . implode(',', array_map(
            fn ($v) => is_finite($v) ? sprintf('%.7f', $v) : '0',
            $queryVector,
        )) . ']';

        if ($useCache) {
            try {
                \Illuminate\Support\Facades\Cache::put($cacheKey, $literal, $ttl);
            } catch (\Throwable $e) {
                // Cache fail-soft: не валим запрос, embed уже получен.
                Log::info('CatalogEmbeddingService: embed cache put failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $procCache[$hash] = $literal;
    }

    /**
     * Догрузить vector_score для конкретных catalog_ids (используется в
     * topNByQueryText для backfill items из code/trgm пулов, которые не
     * вошли в vector top-N). Возвращает map catalog_id => similarity.
     *
     * @param  list<int>  $catalogIds
     * @return array<int, float>
     */
    private function vectorScoresForIds(string $queryText, array $catalogIds, ?int $requestItemId): array
    {
        if (empty($catalogIds)) {
            return [];
        }
        $vectorLiteral = $this->embedQueryToVectorLiteral($queryText, $requestItemId);
        if ($vectorLiteral === null) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($catalogIds), '?'));
        $bindings = array_merge([$vectorLiteral], $catalogIds);
        try {
            $rows = DB::select(
                "SELECT ci.id AS catalog_id, 1 - (e.embedding <=> ?::vector) AS similarity
                 FROM catalog_item_embeddings e
                 JOIN catalog_items ci ON ci.id = e.catalog_item_id
                 WHERE ci.is_active = true AND ci.id IN ($placeholders)",
                $bindings,
            );
        } catch (\Throwable $e) {
            Log::warning('CatalogEmbeddingService: vector backfill failed', [
                'request_item_id' => $requestItemId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->catalog_id] = (float) $r->similarity;
        }
        return $out;
    }

    private function vectorTopN(string $queryText, int $limit, ?int $requestItemId): array
    {
        $vectorLiteral = $this->embedQueryToVectorLiteral($queryText, $requestItemId);
        if ($vectorLiteral === null) {
            return [];
        }

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

        // Hybrid retrieval (code-token ILIKE + trigram word_similarity +
        // vector cosine, с multi-source бонусом) — берём top-N кандидатов
        // (не top-1!), потому что:
        //   - на запросе «БУАД-4-25» code-token совпадает И с «Устройство
        //     БУАД 4-25.8» (искомое), И с «Привод ДК 1200мм, буад 4-25,
        //     двигатель Аир-6» (товар-комплект), оба получают score=0.95;
        //   - vector/trigram сами по себе не разруливают такой tie.
        // Отдаём всех LLM-у на rerank: пусть он выберет ту самую позицию
        // или скажет «никто».
        $topN = (int) app_setting('catalog.name_match.rerank_top_n', 10);
        $topN = max(1, min(15, $topN));

        $allCandidates = $this->topNByQueryText($queryText, $topN, $item->id);
        if ($allCandidates === []) {
            return null;
        }

        // Pre-filter всех кандидатов: threshold + brand_safe + article_safe.
        // Тех, что не прошли — отбрасываем (это «жёсткие» инварианты,
        // LLM-у нет смысла их предлагать). Остаются 0+ кандидатов.
        $safe = [];
        foreach ($allCandidates as $c) {
            if ((float) $c['similarity'] < $threshold) {
                continue;
            }
            if (! $this->isBrandSafe($item, $c['catalog'])) {
                continue;
            }
            if (! $this->isArticleSafe($item->parsed_article, $c['catalog']->brand_article, $c['catalog']->name)) {
                continue;
            }
            $safe[] = $c;
        }
        if ($safe === []) {
            Log::info('CatalogEmbeddingService: all candidates filtered out by safety', [
                'request_item_id' => $item->id,
                'fetched' => count($allCandidates),
                'top1_catalog_id' => $allCandidates[0]['catalog']->id ?? null,
                'top1_similarity' => $allCandidates[0]['similarity'] ?? null,
            ]);
            return null;
        }

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

        // Single safe candidate → binary LLM verify (или skip-if vector ≥ hc).
        if (count($safe) === 1) {
            return $this->finalizeSingleCandidate(
                $item, $safe[0], $hcThreshold, $llmEnabled, $failAction,
            );
        }

        // Multi-candidate. Optimization: если top-1 имеет vector_score >= hc
        // и явно отрывается от #2 (≥0.05) — pure vector сам уверенно лидирует,
        // rerank необязателен.
        $top1 = $safe[0];
        $top1Vector = isset($top1['vector_score']) ? (float) $top1['vector_score'] : 0.0;
        if ($top1Vector >= $hcThreshold) {
            $top2Vector = isset($safe[1]['vector_score']) ? (float) $safe[1]['vector_score'] : 0.0;
            if (($top1Vector - $top2Vector) >= 0.05) {
                return $this->buildMatchResult(
                    $top1, llmValidation: 'skipped_high_confidence', llmReason: null,
                );
            }
        }

        // LLM rerank или fallback к top-1 если LLM выключен.
        if (! $llmEnabled) {
            return $this->buildMatchResult(
                $top1, llmValidation: 'skipped_llm_disabled', llmReason: null,
            );
        }

        $rerank = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $rerank = $this->rerankCandidatesWithLlm($item, $safe);
            if ($rerank !== null) {
                break;
            }
            sleep(5);
        }

        if ($rerank === null) {
            if ($failAction === 'reject') {
                Log::info('CatalogEmbeddingService: rerank LLM failed — match rejected', [
                    'request_item_id' => $item->id,
                    'candidates_count' => count($safe),
                ]);
                return null;
            }
            return $this->buildMatchResult(
                $top1, llmValidation: 'skipped_llm_failed', llmReason: null,
            );
        }

        if ($rerank['index'] === -1) {
            Log::info('CatalogEmbeddingService: rerank LLM rejected all candidates', [
                'request_item_id' => $item->id,
                'candidates_count' => count($safe),
                'reason' => $rerank['reason'],
            ]);
            return null;
        }

        $chosen = $safe[$rerank['index']];
        Log::info('CatalogEmbeddingService: rerank LLM picked candidate', [
            'request_item_id' => $item->id,
            'chosen_index' => $rerank['index'],
            'chosen_catalog_id' => $chosen['catalog']->id,
            'chosen_sku' => $chosen['catalog']->sku,
            'candidates_count' => count($safe),
            'reason' => $rerank['reason'],
        ]);

        return $this->buildMatchResult(
            $chosen, llmValidation: 'approved_rerank', llmReason: $rerank['reason'],
        );
    }

    /**
     * Binary-LLM путь для случая «единственный безопасный кандидат».
     * Skip LLM, если pure vector ≥ hc_threshold; иначе зовём LLM с retry.
     *
     * @param  array{catalog: CatalogItem, similarity: float, method?: string, code_score?: ?float, trgm_score?: ?float, vector_score?: ?float}  $top
     */
    private function finalizeSingleCandidate(
        RequestItem $item,
        array $top,
        float $hcThreshold,
        bool $llmEnabled,
        string $failAction,
    ): ?array {
        /** @var CatalogItem $catalog */
        $catalog = $top['catalog'];
        $similarity = (float) $top['similarity'];
        $vectorScore = isset($top['vector_score']) ? (float) $top['vector_score'] : null;

        $vectorHighConfidence = ($vectorScore !== null && $vectorScore >= $hcThreshold);
        $llmDecision = null;
        if ($llmEnabled && ! $vectorHighConfidence) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $llmDecision = $this->validateMatchWithLlm($item, $catalog, $similarity);
                if ($llmDecision !== null) {
                    break;
                }
                sleep(5);
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
            if ($llmDecision === null && $failAction === 'reject') {
                Log::info('CatalogEmbeddingService: LLM failed — match rejected by llm_fail_action=reject', [
                    'request_item_id' => $item->id,
                    'catalog_id' => $catalog->id,
                    'catalog_sku' => $catalog->sku,
                    'similarity' => $similarity,
                ]);
                return null;
            }
        }

        return $this->buildMatchResult(
            $top,
            llmValidation: $llmDecision === null
                ? ($vectorHighConfidence ? 'skipped_high_confidence' : 'skipped_llm_failed')
                : 'approved',
            llmReason: $llmDecision['reason'] ?? null,
        );
    }

    /**
     * Универсальный return-формат C-step. Из top-кандидата извлекает
     * sub-scores и пакует с финальной LLM-меткой.
     *
     * @param  array{catalog: CatalogItem, similarity: float, method?: string, code_score?: ?float, trgm_score?: ?float, vector_score?: ?float}  $top
     */
    private function buildMatchResult(array $top, string $llmValidation, ?string $llmReason): array
    {
        return [
            'catalog' => $top['catalog'],
            'similarity' => (float) $top['similarity'],
            'method' => (string) ($top['method'] ?? 'vector'),
            'code_score' => isset($top['code_score']) ? (float) $top['code_score'] : null,
            'trgm_score' => isset($top['trgm_score']) ? (float) $top['trgm_score'] : null,
            'vector_score' => isset($top['vector_score']) ? (float) $top['vector_score'] : null,
            'llm_validation' => $llmValidation,
            'llm_reason' => $llmReason,
        ];
    }

    /**
     * Проверка бренда позиции против всех брендов каталога (primary `brand`
     * + secondary `brands[]`). Public — используется в diagnose CLI.
     *
     * Логика:
     *   1. Собираем ВСЕ нормализованные токены клиента (включая ex-brand из
     *      скобок и обрезку организационных префиксов) — normalizeBrandTokens.
     *   2. Аналогично для каталога — `brand` + `brands[]`.
     *   3. Match по любому пересечению: если хоть один client-token совпадает
     *      с любым catalog-token → safe.
     *   4. Fallback для опечаток (ASCII-only): Levenshtein ≤ 1 на токенах
     *      ≥ 5 символов, ≤ 2 на токенах ≥ 8 символов. Cyrillic пропускаем
     *      (PHP levenshtein byte-level — не работает корректно на UTF-8).
     */
    public function isBrandSafe(RequestItem $item, CatalogItem $catalog): bool
    {
        $itemTokens = $this->normalizeBrandTokens($item->brand?->name ?: $item->parsed_brand);
        if (empty($itemTokens)) {
            return true;
        }

        $catalogTokens = [];
        if ($catalog->brand !== null && $catalog->brand !== '') {
            foreach ($this->normalizeBrandTokens($catalog->brand) as $t) {
                $catalogTokens[] = $t;
            }
        }
        if (is_array($catalog->brands)) {
            foreach ($catalog->brands as $b) {
                if (is_string($b) && $b !== '') {
                    foreach ($this->normalizeBrandTokens($b) as $t) {
                        $catalogTokens[] = $t;
                    }
                }
            }
        }
        $catalogTokens = array_values(array_unique(array_filter($catalogTokens, fn ($x) => $x !== '')));
        if (empty($catalogTokens)) {
            return true; // в каталоге бренда нет — не блокируем
        }

        // Exact intersection.
        foreach ($itemTokens as $iTok) {
            if (in_array($iTok, $catalogTokens, true)) {
                return true;
            }
        }

        // ASCII-only fuzzy match: «Shneider» vs «Schneider» (Levenshtein 1).
        // PHP levenshtein на UTF-8 (Cyrillic) считает байты, не символы —
        // даёт ложные срабатывания. Поэтому только латиница+цифры.
        foreach ($itemTokens as $iTok) {
            if (! preg_match('/^[A-Z0-9]+$/', $iTok)) {
                continue;
            }
            $iLen = strlen($iTok);
            if ($iLen < 5) {
                continue;
            }
            foreach ($catalogTokens as $cTok) {
                if (! preg_match('/^[A-Z0-9]+$/', $cTok)) {
                    continue;
                }
                $cLen = strlen($cTok);
                if (abs($iLen - $cLen) > 2) {
                    continue;
                }
                $allowedDist = (min($iLen, $cLen) >= 8) ? 2 : 1;
                if (levenshtein($iTok, $cTok) <= $allowedDist) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Multi-candidate LLM-rerank: даём LLM-у выбрать одного кандидата из
     * списка или сказать null. Используется в matchByRequestItem когда
     * pre-filter оставил >1 безопасного кандидата.
     *
     * Возвращает:
     *   - ['index' => N, 'reason' => '...']  — LLM выбрал кандидата N (0-based)
     *   - ['index' => -1, 'reason' => '...'] — LLM явно сказал «никто не подходит»
     *   - null                                — LLM упал/вернул мусор (caller решает по failAction)
     *
     * @param  list<array{catalog: CatalogItem, similarity: float, method?: string}>  $candidates
     */
    public function rerankCandidatesWithLlm(RequestItem $item, array $candidates): ?array
    {
        if (empty($candidates)) {
            return null;
        }

        $payload = [];
        foreach ($candidates as $c) {
            /** @var CatalogItem $cat */
            $cat = $c['catalog'];
            $payload[] = [
                'brand' => $cat->brand,
                'name' => $cat->name,
                'brand_article' => $cat->brand_article,
                'sku' => (string) $cat->sku,
                'similarity' => (float) $c['similarity'],
                'method' => (string) ($c['method'] ?? 'vector'),
            ];
        }

        // Модель rerank-stage можно переключать через AppSetting — для
        // сложных доменных кейсов (БУАД vs «преобразователь / устройство»)
        // gpt-4o-mini иногда упирается, gpt-4o даёт точнее ответ. Default
        // — clarification_model (mini), но можно переключить на 'gpt-4o'.
        $rerankModel = (string) app_setting(
            'catalog.name_match.rerank_model',
            config('services.openai.clarification_model', 'gpt-4o-mini'),
        );

        // Санитизация client article: если весь parsed_article — локальные
        // коды поставщика (LW-...), передаём LLM-у как «пусто». Иначе
        // LLM воспринимает LW-код как искомый идентификатор и отклоняет
        // всех кандидатов потому что catalog brand_article (TAA*/GAA*/
        // DAA*) не совпадает — а LW это вообще не OEM, в каталоге его нет
        // by design. См. #2350 / #2344.
        $clientArticle = $item->parsed_article;
        if (is_string($clientArticle) && LocalSupplierCodePattern::isAllLocal($clientArticle)) {
            $clientArticle = null;
        }

        try {
            $result = $this->chat->chat(
                [
                    ['role' => 'system', 'content' => \App\Prompts\Catalog\RerankCatalogMatchPrompt::systemMessage()],
                    ['role' => 'user', 'content' => \App\Prompts\Catalog\RerankCatalogMatchPrompt::userMessage(
                        $item->brand?->name ?: $item->parsed_brand,
                        $item->parsed_name,
                        $clientArticle,
                        $payload,
                    )],
                ],
                $rerankModel,
                ['response_format' => ['type' => 'json_object'], 'temperature' => 0, 'max_tokens' => 300],
            );
        } catch (\Throwable $e) {
            Log::warning('CatalogEmbeddingService: rerank LLM call failed (non-fatal)', [
                'request_item_id' => $item->id,
                'candidates_count' => count($candidates),
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $parsed = json_decode((string) ($result['content'] ?? ''), true);
        if (! is_array($parsed)) {
            Log::warning('CatalogEmbeddingService: rerank LLM returned non-JSON', [
                'request_item_id' => $item->id,
                'content' => mb_substr((string) ($result['content'] ?? ''), 0, 200),
            ]);
            return null;
        }

        $reasonStr = is_string($parsed['reason'] ?? null) ? mb_substr($parsed['reason'], 0, 500) : '';
        $idx = $parsed['best_index'] ?? null;

        if ($idx === null) {
            // LLM сказал «никто не подходит» — это валидный ответ, отличный от LLM-сбоя.
            return ['index' => -1, 'reason' => $reasonStr];
        }
        if (! is_int($idx) || $idx < 0 || $idx >= count($candidates)) {
            Log::warning('CatalogEmbeddingService: rerank LLM returned bad index', [
                'request_item_id' => $item->id,
                'index' => $idx,
                'candidates_count' => count($candidates),
            ]);
            return null;
        }
        return ['index' => $idx, 'reason' => $reasonStr];
    }

    /**
     * Бинарная LLM-валидация. Возвращает {same, reason} или null при сбое.
     *
     * @return array{same: bool, reason: string}|null
     */
    public function validateMatchWithLlm(RequestItem $item, CatalogItem $catalog, float $similarity): ?array
    {
        // Санитизация LW-кодов из client article (см. rerankCandidatesWithLlm).
        $clientArticle = $item->parsed_article;
        if (is_string($clientArticle) && LocalSupplierCodePattern::isAllLocal($clientArticle)) {
            $clientArticle = null;
        }

        try {
            $result = $this->chat->chat(
                [
                    ['role' => 'system', 'content' => ValidateCatalogMatchPrompt::systemMessage()],
                    ['role' => 'user', 'content' => ValidateCatalogMatchPrompt::userMessage(
                        $item->brand?->name ?: $item->parsed_brand,
                        $item->parsed_name,
                        $clientArticle,
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
    public function isArticleSafe(?string $itemArticle, ?string $catalogArticle, ?string $catalogName = null): bool
    {
        $catalogNorm = CatalogImportService::normalizeArticle($catalogArticle);
        // Если у каталога артикула нет, проверяем по имени (см. ниже).
        if (($catalogNorm === null || $catalogNorm === '') && ($catalogName === null || $catalogName === '')) {
            return true;
        }
        if ($itemArticle === null || trim($itemArticle) === '') {
            return true;
        }
        // Локальные коды поставщика (LW-...) — это не настоящий OEM,
        // их сравнение с catalog.brand_article (TAA*/GAA*/DAA*) ВСЕГДА
        // даёт «mismatch» и блокирует валидные name-matches. Считаем
        // такой артикул «отсутствующим» — пусть LLM решает по name.
        if (LocalSupplierCodePattern::isAllLocal($itemArticle)) {
            return true;
        }

        // Name-substring fallback. Бывает, что клиент пишет в article
        // фактически имя серии («БУАД-4-25»), а у каталожной позиции
        // brand_article хранит OEM-нумерацию («ЕМРЦ.421243.074-25-05 ТУ»),
        // и эти строки ВООБЩЕ не пересекаются. Но имя в каталоге —
        // «Устройство БУАД 4-25.8 (без проводов)» — содержит ту же
        // подстроку. Если нормализованный client article найден внутри
        // нормализованного catalog name (мин. длина 4) — считаем safe.
        // LLM-rerank финально подтвердит совпадение.
        $catalogNameNorm = '';
        if (is_string($catalogName) && $catalogName !== '') {
            // КРИТИЧЕСКИ важно: применяем cyrillicLookalikeFold (тот же, что
            // в normalizeArticle) ПЕРЕД uppercase + strip. Иначе
            // нормализованный артикул («БУAД425» с латинской A) не найдётся
            // в name каталога («БУАД» с кириллической А) — substring-поиск
            // мажет, валидные матчи блокируются (см. #2363 БУАД).
            $folded = CatalogImportService::cyrillicLookalikeFold($catalogName);
            $catalogNameNorm = (string) preg_replace('/[\s\-_.\/]/u', '', mb_strtoupper($folded));
        }

        // parsed_article может содержать "GAA638JR1, 3RT2016-2GG22" — тот же
        // split что в matchByArticle. Если хоть один токен совпадает с
        // catalog.brand_article — пропускаем (значит B-step тоже бы сматчил,
        // и мы согласны с этим).
        //
        // Prefix-relax: если один нормализованный артикул — префикс другого
        // с разницей в длине ≤5 символов И минимальной длиной ≥4 — считаем
        // safe. Это покрывает кейсы:
        //   - «БУАД-4-25» (клиент дал family) ↔ «БУАД-4-25.8» (catalog SKU
        //     конкретного исполнения): БУАД425 ⊂ БУАД4258, diff=1 → safe;
        //   - «LC1D258» (клиент) ↔ «LC1D258F7C» (catalog Schneider with
        //     модификатор для напряжения): LC1D258 ⊂ LC1D258F7C, diff=3 → safe.
        // Разные модели остаются blocked:
        //   - «6016669154» vs «6088167008» (Bernstein) — не префикс ✗;
        //   - «SCE2G» vs «SCE02» (Allen-Bradley) — не префикс ✗;
        //   - «22214» vs «22220» (подшипник) — не префикс ✗.
        // Финальный verify делает LLM-rerank — даже при prefix-safe LLM
        // отклонит если semantically different.
        // Сначала split по , и /. Но «E10/18» — это ОДИН артикул, где
        // слэш — sub-identifier модели, не разделитель. Поэтому также
        // пробуем ИСХОДНУЮ полную строку как один token.
        $tokens = preg_split('/\s*[,\/]\s*/', $itemArticle) ?: [];
        $tokens[] = $itemArticle; // полную строку — приоритет на exact match

        foreach ($tokens as $tok) {
            $norm = CatalogImportService::normalizeArticle($tok);
            if ($norm === null || $norm === '') {
                continue;
            }
            if ($catalogNorm !== null && $catalogNorm !== '') {
                if ($norm === $catalogNorm) {
                    return true;
                }
                // Prefix relax
                $iLen = mb_strlen($norm);
                $cLen = mb_strlen($catalogNorm);
                $minLen = min($iLen, $cLen);
                $diff = abs($iLen - $cLen);
                if ($minLen >= 4 && $diff <= 5) {
                    if (str_starts_with($catalogNorm, $norm) || str_starts_with($norm, $catalogNorm)) {
                        return true;
                    }
                }
            }
            // Name-substring fallback (см. выше).
            if ($catalogNameNorm !== '' && mb_strlen($norm) >= 4) {
                if (mb_strpos($catalogNameNorm, $norm) !== false) {
                    return true;
                }
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

    /** Legacy single-token нормализация — backwards compat. */
    private function normalizeBrand(?string $b): string
    {
        $tokens = $this->normalizeBrandTokens($b);
        return $tokens[0] ?? '';
    }

    /**
     * Multi-token brand normalization. Возвращает ВСЕ первые слова, по
     * которым позиция/каталог могут быть идентифицированы как один бренд.
     *
     * Обрабатывает:
     *   1. Организационные префиксы (ООО/ОАО/ЗАО/АО/ИП/ПАО/OOO/LLC/LTD/INC/CO)
     *      — пропускаются, берётся следующее слово.
     *      «ООО "Лифт-Комплекс ДС"» → «ЛИФТ»;
     *      «Лифт-Комплекс ДС (LK)» → «ЛИФТ».
     *
     *   2. Ребрендинг «BRAND (ex OLDBRAND)» — добавляет оба бренда в результат.
     *      «AVIRE (ex MEMCO)» → ['AVIRE', 'MEMCO'].
     *
     *   3. Опечатки (только ASCII): не делается тут, обрабатывается в
     *      isBrandSafe через Levenshtein на equal-length tokens.
     *
     * @return list<string>
     */
    private function normalizeBrandTokens(?string $b): array
    {
        if ($b === null || trim($b) === '') {
            return [];
        }

        $variants = [];

        // (ex BRAND) — отдельный alias из скобок (ребрендинг).
        if (preg_match('/\(ex\s+([^)]+)\)/iu', $b, $m)) {
            foreach ($this->normalizeBrandTokens($m[1]) as $v) {
                $variants[] = $v;
            }
        }

        // Primary tokenization: убираем содержимое всех скобок (там либо
        // ex-brand уже распарсен, либо описание-комментарий), затем чистим
        // пунктуацию.
        $s = mb_strtoupper(trim($b));
        $sNoParen = (string) preg_replace('/\([^)]*\)/u', ' ', $s);
        $sNoParen = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $sNoParen);
        $words = preg_split('/\s+/', trim($sNoParen)) ?: [];

        // Skip leading organizational prefixes. Cyrillic + Latin варианты.
        $orgPrefixes = [
            'ООО', 'ОАО', 'ЗАО', 'АО', 'ПАО', 'ИП', 'НПО',
            'OOO', 'OAO', 'ZAO', 'PAO',
            'LLC', 'LTD', 'INC', 'CO', 'GMBH', 'AG', 'SA', 'SAS', 'BV', 'NV',
        ];
        while (! empty($words) && in_array($words[0], $orgPrefixes, true)) {
            array_shift($words);
        }

        if (! empty($words) && $words[0] !== '') {
            $variants[] = $words[0];
        }

        // Multi-word бренды (XIZI OTIS, Schneider Electric, Allen Bradley):
        // intersection по first-word даёт false-negative если client пишет
        // только одно слово, а catalog — full name. Возвращаем ВСЕ
        // значимые слова (длина ≥ 4), кроме generic-частей (ELECTRIC,
        // MOTORS, GROUP — это «суффикс компании», не бренд).
        //
        // Пример: «XIZI OTIS» (catalog) ↔ «OTIS» (client) — без этого
        // intersection ['XIZI'] vs ['OTIS'] был пуст → false brand mismatch,
        // искомые позиции XIZI OTIS дропались в C-step. С этим расширением
        // ['XIZI', 'OTIS'] ∩ ['OTIS'] = ['OTIS'] → safe.
        $genericSuffixes = [
            // English company-form/category words
            'ELECTRIC', 'MOTORS', 'GROUP', 'SYSTEMS', 'COMPANY', 'INDUSTRIAL',
            'PRODUCTS', 'EQUIPMENT', 'TECHNOLOGY', 'CORP', 'CORPORATION',
            'CHINA', 'TURKEY', 'JAPAN', 'GERMANY', 'EUROPE',
            // Russian
            'ЭЛЕКТРИК', 'МОТОР', 'МОТОРС', 'ГРУПП', 'ГРУППА', 'СИСТЕМ', 'СИСТЕМЫ',
            'ЗАВОД', 'ПРОИЗВОДСТВО', 'ОБОРУДОВАНИЕ', 'ТЕХНОЛОГИЯ',
            'РОССИЯ', 'УКРАИНА', 'КИТАЙ',
        ];
        foreach (array_slice($words, 1) as $w) {
            if (mb_strlen($w) < 4) continue;
            if (in_array($w, $genericSuffixes, true)) continue;
            $variants[] = $w;
        }

        return array_values(array_unique(array_filter($variants, fn ($v) => $v !== '')));
    }
}
