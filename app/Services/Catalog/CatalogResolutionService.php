<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use App\Models\RequestItem;
use App\Services\Catalog\CatalogImportService;
use Illuminate\Support\Facades\Log;

/**
 * Резолв позиций заявок, помеченных `internal_catalog_pending`, против
 * `catalog_items` после импорта (Phase 2 — use-case A).
 *
 * Логика на одну `RequestItem`:
 *  1. Извлекаем internal SKU из payload (`quality_assessment_payload.internal_catalog_sku`)
 *     или повторно через regex `M\d{4,}` из parsed_article.
 *  2. Ищем `catalog_items` по `sku`, активные (is_active=true).
 *  3. Если нашли — обновляем item:
 *     - parsed_name: если был пустой / 'M02016' (только артикул) → берём из каталога;
 *     - parsed_brand: если пустой → из каталога;
 *     - quality_assessment_status: `sufficient`;
 *     - quality_assessment_payload: добавляем секцию catalog с snapshot полей.
 *  4. Если не нашли — оставляем `internal_catalog_pending`, ждём следующего snapshot'а.
 *
 * НЕ трогает позиции с другими статусами (sufficient/insufficient/not_covered/...) —
 * у них уже определились через KB-цепочку, перетирать нельзя.
 */
class CatalogResolutionService
{
    /**
     * Use-case A: резолв одной позиции, помеченной internal_catalog_pending.
     * Если в каталоге найден M-SKU — заполняем catalog_item_id, выставляем
     * status=sufficient и фиксируем catalog в payload.
     *
     * Возвращает true если применили апдейт.
     */
    public function resolveItem(RequestItem $item): bool
    {
        if ($item->quality_assessment_status !== 'internal_catalog_pending') {
            return false;
        }

        $sku = $this->extractSku($item);
        if ($sku === null) {
            return false;
        }

        $catalog = CatalogItem::query()
            ->where('sku', $sku)
            ->where('is_active', true)
            ->first();
        if ($catalog === null) {
            return false;
        }

        $this->applyCatalogToItem($item, $catalog, promoteStatus: true, matchMethod: 'A_internal_sku');

        Log::info('CatalogResolutionService: item resolved (A:internal-sku)', [
            'request_item_id' => $item->id,
            'sku' => $sku,
            'catalog_item_id' => $catalog->id,
        ]);

        return true;
    }

    /**
     * Use-case B: матчинг позиции по `parsed_article` через `brand_article`
     * (или sku) каталога — для непользовательских M-кодов. Например клиент
     * написал «3RT2016-2GG22» (артикул Siemens), мы находим в каталоге
     * соответствующую запись и привязываем catalog_item_id.
     *
     * НЕ трогаем quality_assessment_status — статус всё равно определяется
     * KB-цепочкой. Это аддитивная привязка, чтобы в UI можно было
     * показать цену/наличие + бэдж «в каталоге».
     *
     * Идемпотентность: если catalog_item_id уже стоит — выходим, ничего
     * не делаем (даже если можно было бы нашли другое совпадение —
     * нынешняя привязка считается приоритетнее).
     *
     * `parsed_article` может содержать несколько токенов через ", " или "/".
     * Берём первый, который успешно сматчится.
     *
     * Возвращает true если применили апдейт.
     */
    public function matchByArticle(RequestItem $item): bool
    {
        if ($item->catalog_item_id !== null) {
            return false;
        }
        // Priority 1: оператор пометил позицию как «нет в каталоге» —
        // не перепривязываем через bulk passes, ждём ручной refresh.
        if ($item->quality_assessment_status === 'internal_catalog_not_found') {
            return false;
        }
        $article = (string) ($item->parsed_article ?? '');
        if ($article === '') {
            return false;
        }

        // Разбиваем по запятой/слэшу — клиент может прислать «GAA638JR1, 3RT2016-2GG22»
        // (наш парсер сам тоже так пишет — см. ParseItemsPrompt v5).
        $tokens = preg_split('/\s*[,\/]\s*/', $article) ?: [$article];
        foreach ($tokens as $tok) {
            $norm = CatalogImportService::normalizeArticle($tok);
            if ($norm === null || $norm === '') {
                continue;
            }

            // Сначала пробуем как sku (на случай если клиент написал точный
            // M-SKU, например «M02016»), потом как brand_article_normalized.
            $catalog = CatalogItem::query()
                ->where('is_active', true)
                ->where(function ($q) use ($norm) {
                    $q->where('sku', $norm)
                        ->orWhere('brand_article_normalized', $norm);
                })
                ->first();
            if ($catalog === null) {
                continue;
            }

            $this->applyCatalogToItem($item, $catalog, promoteStatus: false, matchMethod: 'B_brand_article');

            Log::info('CatalogResolutionService: item matched (B:brand-article)', [
                'request_item_id' => $item->id,
                'matched_token' => $tok,
                'normalized' => $norm,
                'catalog_item_id' => $catalog->id,
                'catalog_sku' => $catalog->sku,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Use-case C: семантический матчинг по name через pgvector-эмбеддинги.
     * Запускается только когда A и B не нашли. Использует общий
     * embedding-индекс (см. CatalogEmbeddingService).
     *
     * Возвращает true если применили апдейт.
     */
    public function matchByName(RequestItem $item): bool
    {
        if ($item->catalog_item_id !== null) {
            return false;
        }
        // Priority 1: оператор пометил позицию как «нет в каталоге» —
        // C-step (vector + LLM) тоже не запускаем. Возврат — только через
        // ручной refresh (RequestItemEditor::refreshFromCatalog), он сам
        // обнулит status перед вызовом matchByName().
        if ($item->quality_assessment_status === 'internal_catalog_not_found') {
            return false;
        }
        if (! (bool) app_setting('catalog.name_match.enabled', config('services.catalog_name_match.enabled', true))) {
            return false;
        }

        $svc = app(\App\Services\Catalog\CatalogEmbeddingService::class);
        $match = $svc->matchByRequestItem($item);
        if ($match === null) {
            return false;
        }

        /** @var CatalogItem $catalog */
        $catalog = $match['catalog'];
        $similarity = (float) $match['similarity'];

        $extra = [
            'name_vector_similarity' => $similarity,
            'llm_validation' => $match['llm_validation'] ?? null,
        ];
        if (! empty($match['llm_reason'])) {
            $extra['llm_reason'] = $match['llm_reason'];
        }
        $this->applyCatalogToItem($item, $catalog, promoteStatus: false, matchMethod: 'C_name_vector', extraPayload: $extra);

        Log::info('CatalogResolutionService: item matched (C:name-vector)', [
            'request_item_id' => $item->id,
            'catalog_item_id' => $catalog->id,
            'catalog_sku' => $catalog->sku,
            'similarity' => $similarity,
            'llm_validation' => $match['llm_validation'] ?? null,
        ]);

        return true;
    }

    /**
     * Try A (internal-sku resolve), then B (article-match), then C
     * (name-vector match). Используется после импорта каталога и при
     * пост-парсе позиций.
     */
    public function matchOrResolve(RequestItem $item): bool
    {
        if ($this->resolveItem($item)) {
            return true;
        }
        if ($this->matchByArticle($item)) {
            return true;
        }

        return $this->matchByName($item);
    }

    /**
     * Bulk: pass over всех несматченных позиций (use-case A + B + C).
     *
     * Сканирует:
     *  - items со status=internal_catalog_pending — пытается резолвить через
     *    M-SKU (resolveItem);
     *  - items с catalog_item_id IS NULL и непустым parsed_article — пытается
     *    сматчить через brand_article / sku (matchByArticle);
     *  - если и B не нашёл — пробует C (matchByName) если у item есть
     *    parsed_name.
     *
     * Используется после успешного импорта каталога (ResolvePendingFromCatalogJob).
     *
     * @return array{checked: int, resolved_a: int, matched_b: int, matched_c: int}
     */
    public function resolveAllPending(): array
    {
        $checked = 0;
        $resolvedA = 0;
        $matchedB = 0;
        $matchedC = 0;

        RequestItem::query()
            ->where('is_active', true)
            ->whereNull('catalog_item_id')
            ->where(function ($q) {
                $q->where('quality_assessment_status', 'internal_catalog_pending')
                    ->orWhereNotNull('parsed_article')
                    ->orWhereNotNull('parsed_name');
            })
            ->chunkById(200, function ($items) use (&$checked, &$resolvedA, &$matchedB, &$matchedC) {
                foreach ($items as $item) {
                    $checked++;
                    if ($this->resolveItem($item)) {
                        $resolvedA++;
                        continue;
                    }
                    if ($this->matchByArticle($item)) {
                        $matchedB++;
                        continue;
                    }
                    if ($this->matchByName($item)) {
                        $matchedC++;
                    }
                }
            });

        Log::info('CatalogResolutionService: bulk pass done', [
            'checked' => $checked,
            'resolved_a' => $resolvedA,
            'matched_b' => $matchedB,
            'matched_c' => $matchedC,
        ]);

        return [
            'checked' => $checked,
            'resolved_a' => $resolvedA,
            'matched_b' => $matchedB,
            'matched_c' => $matchedC,
            // Совместимость с прежним именованием:
            'resolved' => $resolvedA,
        ];
    }

    private function extractSku(RequestItem $item): ?string
    {
        $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];
        if (! empty($payload['internal_catalog_sku'])) {
            return CatalogImportService::cyrillicLookalikeFold((string) $payload['internal_catalog_sku']);
        }
        $article = (string) ($item->parsed_article ?? '');
        if ($article === '') {
            return null;
        }
        // Cyrillic→latin fold перед regex (см. QualityAssessmentService::detectInternalCatalogSku).
        $article = CatalogImportService::cyrillicLookalikeFold($article);
        $pattern = '/(?<![\p{L}\p{N}_])(M\d{4,})(?![\p{L}\p{N}_])/u';
        if (preg_match($pattern, $article, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param bool   $promoteStatus  Если true — выставит status=sufficient и
     *                               запишет reason=catalog_resolved в payload
     *                               (use-case A: M-SKU точно идентифицирует
     *                               товар). Если false — только привязываем
     *                               catalog_item_id, status не трогаем
     *                               (use-case B: brand_article-match — KB может
     *                               ещё что-то сказать про категорию/extractors).
     * @param string $matchMethod    Маркер метода матчинга, пишется в payload:
     *                               'A_internal_sku' | 'B_brand_article' | 'C_name_vector'.
     *                               Используется для точечного rollback'а
     *                               (например, переcматчинг только C-step
     *                               после смены threshold).
     * @param array<string, mixed> $extraPayload  Доп. поля в payload->catalog_match
     *                               (например, similarity для C-step).
     */
    private function applyCatalogToItem(
        RequestItem $item,
        CatalogItem $catalog,
        bool $promoteStatus,
        string $matchMethod,
        array $extraPayload = [],
    ): void {
        $item->catalog_item_id = $catalog->id;

        // parsed_name: если у позиции имя пустое или это просто SKU
        // (типичный сценарий — клиент прислал «Артикул: M02016 — 5 шт»,
        // парсер записал name=«M02016»), берём название из каталога.
        $name = (string) ($item->parsed_name ?? '');
        if ($name === '' || $name === $catalog->sku) {
            $item->parsed_name = mb_substr((string) $catalog->name, 0, 250);
        }

        if (empty($item->parsed_brand) && ! empty($catalog->brand)) {
            $item->parsed_brand = $catalog->brand;
        }

        $payload = is_array($item->quality_assessment_payload) ? $item->quality_assessment_payload : [];

        // Маркер метода для DB-аналитики и точечного rollback'а.
        $payload['catalog_match'] = array_merge([
            'method' => $matchMethod,
            'matched_at' => now()->toIso8601String(),
            'catalog_item_id' => $catalog->id,
            'catalog_sku' => $catalog->sku,
        ], $extraPayload);

        if ($promoteStatus) {
            $payload['phase'] = 'completed';
            $payload['resolved_at'] = now()->toIso8601String();
            $payload['reason'] = 'catalog_resolved';
            $payload['catalog'] = [
                'catalog_item_id' => $catalog->id,
                'sku' => $catalog->sku,
                'brand' => $catalog->brand,
                'brand_article' => $catalog->brand_article,
                'unit_name' => $catalog->unit_name,
                'part_type' => $catalog->part_type,
                'form_factor' => $catalog->form_factor,
                'price' => $catalog->price,
                'stock_available' => $catalog->stock_available,
            ];
            $item->quality_assessment_status = 'sufficient';
        }

        $item->quality_assessment_payload = $payload;
        $item->save();
    }
}
