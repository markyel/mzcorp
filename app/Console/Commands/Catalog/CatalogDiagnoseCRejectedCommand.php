<?php

namespace App\Console\Commands\Catalog;

use App\Models\RequestItem;
use App\Services\Catalog\CatalogEmbeddingService;
use Illuminate\Console\Command;

/**
 * Диагностика C-step (hybrid retrieval + LLM) — почему он мало кого матчит.
 *
 * Идёт по items с `catalog_item_id IS NULL` и непустым parsed_name,
 * для каждого:
 *   1) строит query text как CatalogEmbeddingService::buildQueryText;
 *   2) hybrid top-1 (CatalogEmbeddingService::topNByQueryText):
 *      code-token ILIKE + trigram word_similarity + vector cosine,
 *      blended score с multi-source бонусом, method='code|trgm|vector|multi';
 *   3) если similarity ≥ --min-sim — запускает safety stage'и:
 *       - brand check (item.brand vs catalog.brand + catalog.brands[]);
 *       - article check (CatalogEmbeddingService::isArticleSafe — теперь
 *         игнорирует локальные коды поставщика типа LW-...);
 *       - LLM validation (CatalogEmbeddingService::validateMatchWithLlm).
 *         Skip только если vector_score сам по себе ≥ hc_threshold (0.90);
 *         для code/trgm-only LLM всегда дёргается.
 *   4) фиксирует причину отказа и складывает в bucket для отчёта.
 *
 * НИЧЕГО НЕ ПИШЕТ в БД — это read-only диагностика.
 *
 * Usage:
 *   php artisan catalog:diagnose-c-rejected
 *   php artisan catalog:diagnose-c-rejected --limit=20 --min-sim=0.65
 *   php artisan catalog:diagnose-c-rejected --limit=20 --min-sim=0.60 --no-llm
 *   php artisan catalog:diagnose-c-rejected --item=12345
 */
class CatalogDiagnoseCRejectedCommand extends Command
{
    protected $signature = 'catalog:diagnose-c-rejected
        {--limit=20 : Сколько items с найденным top-кандидатом собрать в отчёт}
        {--scan-limit=500 : Максимум items обойти (для контроля стоимости embed)}
        {--min-sim=0.65 : Минимальный similarity, ниже — пропускаем как «вектор не нашёл»}
        {--item= : Диагностика конкретного request_item.id}
        {--no-llm : Не дёргать LLM-валидацию (только vector + safety)}
        {--source=any : Источник: any|text|photo (отделить vision-извлечённые)}';

    protected $description = 'Diagnose C-step rejections: vector found candidate >= min-sim, но финального match нет.';

    public function handle(CatalogEmbeddingService $svc): int
    {
        $limit = (int) $this->option('limit');
        $scanLimit = (int) $this->option('scan-limit');
        $minSim = (float) $this->option('min-sim');
        $skipLlm = (bool) $this->option('no-llm');
        $singleId = $this->option('item') ? (int) $this->option('item') : null;
        $source = (string) $this->option('source');

        $query = RequestItem::query()
            ->where('is_active', true)
            ->whereNull('catalog_item_id')
            ->whereNotNull('parsed_name')
            ->where('parsed_name', '<>', '');

        if ($singleId !== null) {
            $query->where('id', $singleId);
        } else {
            $query->where(function ($q) {
                $q->whereNull('quality_assessment_status')
                  ->orWhere('quality_assessment_status', '!=', 'internal_catalog_not_found');
            });
            // photo = пришёл из Vision-парса (image_attachment_id заполнен);
            // text  = чисто текстовая позиция (image_attachment_id IS NULL);
            // any   = всё подряд (default).
            if ($source === 'photo') {
                $query->whereNotNull('image_attachment_id');
            } elseif ($source === 'text') {
                $query->whereNull('image_attachment_id');
            }
            $query->orderBy('id', 'desc')->limit($scanLimit);
        }

        $items = $query->get();
        if ($items->isEmpty()) {
            $this->warn('Кандидатов не найдено.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Сканирую до %d items, ищу %d с vector ≥ %.2f. LLM: %s.',
            $items->count(),
            $limit,
            $minSim,
            $skipLlm ? 'OFF' : 'ON',
        ));
        $this->line('');

        $collected = [];
        $buckets = [
            'no_query_text' => 0,
            'embed_failed' => 0,
            'no_candidate' => 0,
            'below_min_sim' => 0,
            'brand_mismatch' => 0,
            'article_mismatch' => 0,
            'llm_rejected' => 0,
            'llm_failed' => 0,
            'would_match' => 0,
        ];

        foreach ($items as $item) {
            if (count($collected) >= $limit) {
                break;
            }

            $queryText = $svc->buildQueryText($item);
            if (mb_strlen(trim($queryText)) < 5) {
                $buckets['no_query_text']++;
                continue;
            }

            // Hybrid retrieval (code+trgm+vector) — та же логика, что и
            // в matchByRequestItem после рефактора. Видим blended score
            // и method чтобы понимать, какой источник «вытянул» матч.
            $top1 = $svc->topNByQueryText($queryText, 1, $item->id);
            if ($top1 === []) {
                $buckets['no_candidate']++;
                continue;
            }
            $cand = $top1[0];
            $catalog = $cand['catalog'];
            $similarity = (float) $cand['similarity'];
            $matchMethod = (string) ($cand['method'] ?? 'vector');
            $codeScore = isset($cand['code_score']) ? (float) $cand['code_score'] : null;
            $trgmScore = isset($cand['trgm_score']) ? (float) $cand['trgm_score'] : null;
            $vectorScore = isset($cand['vector_score']) ? (float) $cand['vector_score'] : null;

            if ($similarity < $minSim) {
                $buckets['below_min_sim']++;
                continue;
            }

            // Stage 1: brand check (упрощённо — без normalize, для отчёта
            // показываем raw данные оператору).
            $itemBrandRaw = $item->brand?->name ?: $item->parsed_brand;
            $itemBrand = $this->firstWordUpper($itemBrandRaw);
            $catalogBrands = [];
            if (! empty($catalog->brand)) {
                $catalogBrands[] = $this->firstWordUpper($catalog->brand);
            }
            if (is_array($catalog->brands)) {
                foreach ($catalog->brands as $b) {
                    if (is_string($b) && $b !== '') {
                        $catalogBrands[] = $this->firstWordUpper($b);
                    }
                }
            }
            $catalogBrands = array_values(array_unique(array_filter($catalogBrands, fn ($x) => $x !== '')));
            $brandMismatch = ($itemBrand !== '' && ! empty($catalogBrands)
                && ! in_array($itemBrand, $catalogBrands, true));

            // Stage 2: article safety.
            $articleSafe = $svc->isArticleSafe($item->parsed_article, $catalog->brand_article);

            // Stage 3: LLM (если есть смысл).
            $llmVerdict = null;
            $llmReason = null;
            $reason = null;

            if ($brandMismatch) {
                $reason = 'brand_mismatch';
                $buckets['brand_mismatch']++;
            } elseif (! $articleSafe) {
                $reason = 'article_mismatch';
                $buckets['article_mismatch']++;
            } elseif (! $skipLlm) {
                // hc_threshold default 0.90: skip LLM ТОЛЬКО если pure
                // vector сам по себе уже даёт ≥ hc. Для code/trgm-only
                // матчей LLM всегда дёргаем — substring без семантики.
                $hcThreshold = (float) app_setting('catalog.name_match.hc_threshold', 0.90);
                $vectorHc = ($vectorScore !== null && $vectorScore >= $hcThreshold);
                if ($vectorHc) {
                    $reason = 'would_match';
                    $llmVerdict = 'skipped_high_confidence';
                    $buckets['would_match']++;
                } else {
                    $decision = $svc->validateMatchWithLlm($item, $catalog, $similarity);
                    if ($decision === null) {
                        $reason = 'llm_failed';
                        $llmVerdict = 'failed';
                        $buckets['llm_failed']++;
                    } elseif ($decision['same'] === false) {
                        $reason = 'llm_rejected';
                        $llmVerdict = 'rejected';
                        $llmReason = $decision['reason'];
                        $buckets['llm_rejected']++;
                    } else {
                        $reason = 'would_match';
                        $llmVerdict = 'approved';
                        $llmReason = $decision['reason'];
                        $buckets['would_match']++;
                    }
                }
            } else {
                $reason = 'would_match_skipped_llm';
                $buckets['would_match']++;
            }

            $collected[] = [
                'item_id' => $item->id,
                'item_brand' => (string) $itemBrandRaw,
                'item_name' => (string) $item->parsed_name,
                'item_article' => (string) $item->parsed_article,
                'src' => $item->image_attachment_id !== null ? 'photo' : 'text',
                'sim' => $similarity,
                'method' => $matchMethod,
                'code_score' => $codeScore,
                'trgm_score' => $trgmScore,
                'vector_score' => $vectorScore,
                'cat_id' => $catalog->id,
                'cat_sku' => (string) $catalog->sku,
                'cat_brand' => (string) $catalog->brand,
                'cat_name' => (string) $catalog->name,
                'cat_article' => (string) $catalog->brand_article,
                'reason' => $reason,
                'llm' => $llmVerdict,
                'llm_reason' => $llmReason,
                'brand_mismatch' => $brandMismatch,
                'article_safe' => $articleSafe,
            ];

            $this->printOne($collected[count($collected) - 1]);
        }

        $this->line('');
        $this->line(str_repeat('═', 80));
        $this->info('СВОДКА:');
        foreach ($buckets as $k => $v) {
            if ($v === 0) continue;
            $this->line(sprintf('  %-22s %d', $k, $v));
        }
        $this->line('');
        $this->line(sprintf(
            'Собрано %d / лимит %d. Бакет «could-match» включает high-confidence skip и LLM approved.',
            count($collected),
            $limit,
        ));

        return self::SUCCESS;
    }

    private function printOne(array $r): void
    {
        $color = match ($r['reason']) {
            'brand_mismatch' => 'yellow',
            'article_mismatch' => 'yellow',
            'llm_rejected' => 'red',
            'llm_failed' => 'magenta',
            'would_match', 'would_match_skipped_llm' => 'green',
            default => 'white',
        };

        $this->line('');
        $subs = [];
        if ($r['code_score'] !== null) $subs[] = sprintf('code=%.2f', $r['code_score']);
        if ($r['trgm_score'] !== null) $subs[] = sprintf('trgm=%.2f', $r['trgm_score']);
        if ($r['vector_score'] !== null) $subs[] = sprintf('vec=%.2f', $r['vector_score']);
        $subsStr = $subs === [] ? '' : '  [' . implode(' ', $subs) . ']';

        $this->line(sprintf(
            '<fg=%s>#%d  src=%s  sim=%.3f  via=%s%s  reason=%s</>',
            $color,
            $r['item_id'],
            $r['src'],
            $r['sim'],
            $r['method'] ?? '?',
            $subsStr,
            $r['reason'],
        ));
        $this->line(sprintf(
            '  REQ: [%s] %s | art=[%s]',
            mb_substr($r['item_brand'], 0, 20),
            mb_substr($r['item_name'], 0, 60),
            mb_substr($r['item_article'], 0, 30),
        ));
        $this->line(sprintf(
            '  CAT: [%s] %s | art=[%s] sku=%s',
            mb_substr($r['cat_brand'], 0, 20),
            mb_substr($r['cat_name'], 0, 60),
            mb_substr($r['cat_article'], 0, 30),
            $r['cat_sku'],
        ));
        if ($r['llm_reason']) {
            $this->line('  LLM: ' . mb_substr($r['llm_reason'], 0, 200));
        }
    }

    /** Первое слово в UPPER, без пунктуации — как CatalogEmbeddingService::normalizeBrand. */
    private function firstWordUpper(?string $b): string
    {
        if ($b === null || $b === '') {
            return '';
        }
        $s = mb_strtoupper(trim($b));
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        return explode(' ', trim((string) $s))[0] ?? '';
    }
}
