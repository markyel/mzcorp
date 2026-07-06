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
     * Минимальная длина нормализованного токена для ТОЧНОГО article-матча
     * (sku / brand_article / articles[]). Короткие обрывки вроде «2», «04»
     * (напр. хвост «АИР80S6/2» после split по «/») неинформативны и ловят мусор
     * в articles[]. Кейс M-2026-3418: токен «2» сматчился с кнопкой M18255, у
     * которой в articles[] лежит "2" (из названия «Кнопка приказа "2"»). Такие
     * токены пропускаем — их подберёт C-step (поиск по name).
     */
    private const MIN_ARTICLE_TOKEN_LEN = 4;

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

        // Fallback: parsed_article пуст, но parsed_name выглядит как
        // одиночный код (например, Vision положил «M04557» в name,
        // а article остался пустым). Считаем name SKU-подобным если:
        // нет пробелов, есть и буква, и цифра, длина 3..32. Это отсекает
        // обычные названия вроде «Плата ПКЛ-32» (есть пробел) и пустые
        // паттерны типа «AAAA» / «32» (нет смеси букв+цифр).
        if ($article === '') {
            $name = trim((string) ($item->parsed_name ?? ''));
            $isSkuLikeName = $name !== ''
                && mb_strlen($name) >= 3
                && mb_strlen($name) <= 32
                && ! preg_match('/\s/u', $name)
                && preg_match('/\p{L}/u', $name)
                && preg_match('/\d/u', $name);
            if ($isSkuLikeName) {
                $article = $name;
            }
        }

        if ($article === '') {
            return false;
        }

        // Разбиваем по запятой/слэшу — клиент может прислать «GAA638JR1, 3RT2016-2GG22»
        // (наш парсер сам тоже так пишет — см. ParseItemsPrompt v5).
        $tokens = preg_split('/\s*[,\/]\s*/', $article) ?: [$article];
        foreach ($tokens as $tok) {
            // Локальные коды поставщика (LW-..., см. LocalSupplierCodePattern)
            // в каталоге заведомо отсутствуют (там лежит оригинальный
            // OEM-артикул вроде DAA332N2 или GAA50AHA1-6M). Пропускаем,
            // чтобы не тратить DB-запросы и не вводить в заблуждение
            // последующие шаги — пусть C-step ищет по name.
            if (LocalSupplierCodePattern::isLocalToken($tok)) {
                continue;
            }
            $norm = CatalogImportService::normalizeArticle($tok);
            if ($norm === null || $norm === '') {
                continue;
            }
            // Слишком короткий токен — неинформативен для точного article-матча
            // и ловит мусор в articles[] (см. MIN_ARTICLE_TOKEN_LEN). Отдаём C-step.
            if (mb_strlen($norm) < self::MIN_ARTICLE_TOKEN_LEN) {
                continue;
            }

            // Сначала пробуем как sku (на случай если клиент написал точный
            // M-SKU, например «M02016»), потом как brand_article_normalized
            // (primary OEM-артикул). Если и это не нашло — лезем в массив
            // `articles[]`: у multi-OEM позиций там лежат ВСЕ артикулы
            // совместимых исполнений, а `brand_article` хранит только первый.
            //
            // Кейс M-2026-0921 (M16660 «Плата ПКЛ32-04»):
            //   brand_article = "ЕИЛА.758727.772-04"   ← primary
            //   articles      = ["ЕИЛА.758727.772-04", "ЕИЛА.687255.008-04"]
            // Клиент написал второй артикул — без поиска по articles[] match
            // не находил, хотя товар в каталоге явно есть.
            //
            // Normalize применяем на каждом элементе jsonb-массива на стороне
            // Postgres: pattern такой же как в normalizeArticle (cyrillic-fold
            // делать в SQL не нужно — на стороне Postgres сравниваем raw vs
            // raw, fold уже отработал в clientskй $norm и при импорте).
            // Digit-only signature — устойчивый сигнал для multi-OEM поиска
            // когда буквы не совпадают (cyrillic vs Latin или Vision OCR ошибки).
            // Применяем только когда цифр ≥8 (защита от ложных совпадений
            // на коротких числовых обрывках типа «GAA638»).
            $digitSig = preg_replace('/\D/', '', $norm) ?? '';
            $useDigitSig = strlen($digitSig) >= 8;

            $catalog = CatalogItem::query()
                ->where('is_active', true)
                ->where(function ($q) use ($norm, $digitSig, $useDigitSig) {
                    $q->where('sku', $norm)
                        ->orWhere('brand_article_normalized', $norm)
                        // EXISTS по массиву articles[] — точное совпадение
                        // полной normalized-формы любого из артикулов товара.
                        ->orWhereRaw(
                            "EXISTS (SELECT 1 FROM jsonb_array_elements_text(articles) AS a
                                     WHERE upper(regexp_replace(a, '[\\s\\-_./]', '', 'g')) = ?)",
                            [$norm]
                        );
                    if ($useDigitSig) {
                        // EXISTS по digit-only сигнатуре. Это «спасает» case'ы
                        // когда буквенная часть отличается: Vision OCR
                        // «EMMA.687255.008-04» vs catalog «ЕИЛА.687255.008-04»
                        // (галлюцинация букв), или Latin-vs-Cyrillic letters
                        // где cyrillic-fold не покрывает (И, Л, Я и т.п.).
                        // Цифровая часть однозначна.
                        $q->orWhereRaw(
                            "EXISTS (SELECT 1 FROM jsonb_array_elements_text(articles) AS a
                                     WHERE regexp_replace(a, '\\D', '', 'g') = ?)",
                            [$digitSig]
                        );
                    }
                })
                ->first();
            if ($catalog === null) {
                // Шаг D: выученный алиас — менеджеры уже повторно привязывали
                // этот код к конкретной M-позиции руками (LearnedAliasService).
                // Точный матч бессилен (код в склейке/названии/опечатке 1С),
                // а подтверждённое людьми соответствие — надёжный сигнал.
                $learned = app(LearnedAliasService::class)->lookup($norm);
                if ($learned !== null) {
                    $this->applyCatalogToItem(
                        $item,
                        $learned,
                        promoteStatus: false,
                        matchMethod: 'D_learned_alias',
                        extraPayload: [
                            'matched_token' => $tok,
                            'matched_token_normalized' => $norm,
                        ],
                    );
                    Log::info('CatalogResolutionService: item matched (D:learned-alias)', [
                        'request_item_id' => $item->id,
                        'matched_token' => $tok,
                        'normalized' => $norm,
                        'catalog_item_id' => $learned->id,
                        'catalog_sku' => $learned->sku,
                    ]);

                    return true;
                }

                continue;
            }

            $this->applyCatalogToItem(
                $item,
                $catalog,
                promoteStatus: false,
                matchMethod: 'B_brand_article',
                extraPayload: [
                    // matched_token нужен complexity-определителю
                    // (MatchPath::detect) чтобы понять: клиент дал M-арт
                    // (matched_token = M\d+) или OEM-код (всё прочее).
                    // cat_sku сам по себе всегда M (наш внутренний SKU)
                    // и не различает эти случаи.
                    'matched_token' => $tok,
                    'matched_token_normalized' => $norm,
                ],
            );

            Log::info('CatalogResolutionService: item matched (B:brand-article)', [
                'request_item_id' => $item->id,
                'matched_token' => $tok,
                'normalized' => $norm,
                'catalog_item_id' => $catalog->id,
                'catalog_sku' => $catalog->sku,
                'catalog_primary_article' => $catalog->brand_article,
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
            // Backwards-compat: имя поля «name_vector_similarity», хотя теперь
            // это blended score (code+trgm+vector). Для UI / диагностики
            // дополнительно пишем method и sub-scores.
            'name_vector_similarity' => $similarity,
            'name_match_method' => $match['method'] ?? null,        // code | trgm | vector | multi
            'name_match_sub_scores' => [
                'code' => $match['code_score'] ?? null,
                'trgm' => $match['trgm_score'] ?? null,
                'vector' => $match['vector_score'] ?? null,
            ],
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
            'method' => $match['method'] ?? null,
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
