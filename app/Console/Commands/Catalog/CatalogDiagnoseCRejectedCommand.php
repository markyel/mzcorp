<?php

namespace App\Console\Commands\Catalog;

use App\Models\RequestItem;
use App\Services\Catalog\CatalogEmbeddingService;
use Illuminate\Console\Command;

/**
 * Диагностика C-step (hybrid retrieval + multi-candidate LLM rerank) —
 * почему он мало кого матчит.
 *
 * Идёт по items с `catalog_item_id IS NULL` и непустым parsed_name,
 * для каждого:
 *   1) buildQueryText (LW-* токены НЕ подмешиваются в embed-текст);
 *   2) topNByQueryText(top-N) — hybrid retrieval с blended score;
 *   3) pre-filter: similarity ≥ --min-sim + isBrandSafe + isArticleSafe;
 *   4) если 0 safe → бакет all_filtered (по доминирующей причине);
 *      если 1 safe → binary LLM (skip-if vector ≥ hc);
 *      если >1 safe → multi-candidate LLM rerank (выбирает или говорит null).
 *   5) фиксирует причину/исход и складывает в bucket для отчёта.
 *
 * НИЧЕГО НЕ ПИШЕТ в БД — это read-only диагностика.
 *
 * Usage:
 *   php artisan catalog:diagnose-c-rejected
 *   php artisan catalog:diagnose-c-rejected --limit=20 --top-n=5
 *   php artisan catalog:diagnose-c-rejected --no-llm --limit=50
 *   php artisan catalog:diagnose-c-rejected --item=12345 --top-n=7
 */
class CatalogDiagnoseCRejectedCommand extends Command
{
    protected $signature = 'catalog:diagnose-c-rejected
        {--limit=20 : Сколько items с найденным top-кандидатом собрать в отчёт}
        {--scan-limit=500 : Максимум items обойти (для контроля стоимости embed)}
        {--min-sim=0.65 : Минимальный similarity, ниже — пропускаем как «вектор не нашёл»}
        {--top-n=5 : Сколько кандидатов запрашивать у hybrid retrieval}
        {--item= : Диагностика конкретного request_item.id}
        {--no-llm : Не дёргать LLM-валидацию (только vector + safety)}
        {--source=any : Источник: any|text|photo (отделить vision-извлечённые)}';

    protected $description = 'Diagnose C-step: hybrid retrieval + safety + rerank LLM, причины отказов.';

    public function handle(CatalogEmbeddingService $svc): int
    {
        $limit = (int) $this->option('limit');
        $scanLimit = (int) $this->option('scan-limit');
        $minSim = (float) $this->option('min-sim');
        $topN = max(1, min(10, (int) $this->option('top-n')));
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
            'Сканирую до %d items, top-N=%d, min-sim=%.2f. LLM: %s.',
            $items->count(), $topN, $minSim, $skipLlm ? 'OFF' : 'ON',
        ));
        $this->line('');

        $collected = [];
        $buckets = [
            'no_query_text' => 0,
            'no_candidate' => 0,
            'all_below_min_sim' => 0,
            'all_brand_mismatch' => 0,
            'all_article_mismatch' => 0,
            'mixed_filtered' => 0,
            'binary_llm_rejected' => 0,
            'binary_llm_failed' => 0,
            'rerank_picked' => 0,
            'rerank_rejected_all' => 0,
            'rerank_failed' => 0,
            'would_match_single' => 0,
            'would_match_hc_skip' => 0,
            'multi_skipped_llm' => 0,
        ];
        $hcThreshold = (float) app_setting('catalog.name_match.hc_threshold', 0.90);

        foreach ($items as $item) {
            if (count($collected) >= $limit) {
                break;
            }

            $queryText = $svc->buildQueryText($item);
            if (mb_strlen(trim($queryText)) < 5) {
                $buckets['no_query_text']++;
                continue;
            }

            $allCands = $svc->topNByQueryText($queryText, $topN, $item->id);
            if ($allCands === []) {
                $buckets['no_candidate']++;
                continue;
            }

            // Pre-filter с подсчётом причин отсева.
            $safe = [];
            $filterReasons = ['below_min_sim' => 0, 'brand' => 0, 'article' => 0];
            foreach ($allCands as $c) {
                if ((float) $c['similarity'] < $minSim) {
                    $filterReasons['below_min_sim']++;
                    continue;
                }
                if (! $svc->isBrandSafe($item, $c['catalog'])) {
                    $filterReasons['brand']++;
                    continue;
                }
                if (! $svc->isArticleSafe($item->parsed_article, $c['catalog']->brand_article)) {
                    $filterReasons['article']++;
                    continue;
                }
                $safe[] = $c;
            }

            $itemBrandRaw = (string) ($item->brand?->name ?: $item->parsed_brand ?? '');

            // Случай 0 safe → определить доминирующую причину.
            if ($safe === []) {
                $top = $allCands[0];
                $bucket = $this->dominantFilterBucket($filterReasons);
                $buckets[$bucket]++;
                $collected[] = $this->buildRow($item, $top, $itemBrandRaw, 'filtered:' . $bucket, null, null, $filterReasons, count($safe), count($allCands));
                $this->printOne($collected[count($collected) - 1]);
                continue;
            }

            // Случай ровно 1 safe → binary LLM (если включён).
            if (count($safe) === 1) {
                $top = $safe[0];
                $vectorScore = isset($top['vector_score']) ? (float) $top['vector_score'] : null;
                $vectorHc = ($vectorScore !== null && $vectorScore >= $hcThreshold);
                if ($skipLlm) {
                    $buckets['multi_skipped_llm']++;
                    $collected[] = $this->buildRow($item, $top, $itemBrandRaw, 'would_match_single (LLM off)', null, null, $filterReasons, 1, count($allCands));
                } elseif ($vectorHc) {
                    $buckets['would_match_hc_skip']++;
                    $collected[] = $this->buildRow($item, $top, $itemBrandRaw, 'would_match_hc_skip', 'skipped_high_confidence', null, $filterReasons, 1, count($allCands));
                } else {
                    $decision = $svc->validateMatchWithLlm($item, $top['catalog'], (float) $top['similarity']);
                    if ($decision === null) {
                        $buckets['binary_llm_failed']++;
                        $collected[] = $this->buildRow($item, $top, $itemBrandRaw, 'binary_llm_failed', 'failed', null, $filterReasons, 1, count($allCands));
                    } elseif ($decision['same'] === false) {
                        $buckets['binary_llm_rejected']++;
                        $collected[] = $this->buildRow($item, $top, $itemBrandRaw, 'binary_llm_rejected', 'rejected', $decision['reason'], $filterReasons, 1, count($allCands));
                    } else {
                        $buckets['would_match_single']++;
                        $collected[] = $this->buildRow($item, $top, $itemBrandRaw, 'would_match_single', 'approved', $decision['reason'], $filterReasons, 1, count($allCands));
                    }
                }
                $this->printOne($collected[count($collected) - 1]);
                continue;
            }

            // Случай >1 safe → multi-candidate rerank.
            if ($skipLlm) {
                $buckets['multi_skipped_llm']++;
                $collected[] = $this->buildRow($item, $safe[0], $itemBrandRaw, 'multi_skipped_llm (LLM off, ' . count($safe) . ' safe)', null, null, $filterReasons, count($safe), count($allCands));
                $this->printOne($collected[count($collected) - 1]);
                continue;
            }

            $rerank = $svc->rerankCandidatesWithLlm($item, $safe);
            if ($rerank === null) {
                $buckets['rerank_failed']++;
                $collected[] = $this->buildRow($item, $safe[0], $itemBrandRaw, 'rerank_failed (' . count($safe) . ' safe)', 'failed', null, $filterReasons, count($safe), count($allCands));
            } elseif ($rerank['index'] === -1) {
                $buckets['rerank_rejected_all']++;
                $collected[] = $this->buildRow($item, $safe[0], $itemBrandRaw, 'rerank_rejected_all (' . count($safe) . ' safe)', 'rejected_all', $rerank['reason'], $filterReasons, count($safe), count($allCands));
            } else {
                $chosen = $safe[$rerank['index']];
                $buckets['rerank_picked']++;
                $collected[] = $this->buildRow($item, $chosen, $itemBrandRaw, 'rerank_picked[' . $rerank['index'] . '] (' . count($safe) . ' safe)', 'approved_rerank', $rerank['reason'], $filterReasons, count($safe), count($allCands));
            }
            $this->printOne($collected[count($collected) - 1]);
        }

        $this->line('');
        $this->line(str_repeat('═', 80));
        $this->info('СВОДКА:');
        foreach ($buckets as $k => $v) {
            if ($v === 0) continue;
            $this->line(sprintf('  %-24s %d', $k, $v));
        }
        $this->line('');
        $this->line(sprintf('Собрано %d / лимит %d.', count($collected), $limit));

        return self::SUCCESS;
    }

    /**
     * @param  array{below_min_sim: int, brand: int, article: int}  $reasons
     */
    private function dominantFilterBucket(array $reasons): string
    {
        $max = max($reasons['below_min_sim'], $reasons['brand'], $reasons['article']);
        if ($max === 0) {
            return 'mixed_filtered';
        }
        if ($reasons['brand'] === $max) return 'all_brand_mismatch';
        if ($reasons['article'] === $max) return 'all_article_mismatch';
        return 'all_below_min_sim';
    }

    /**
     * @param  array{catalog: mixed, similarity: float, method?: string, code_score?: ?float, trgm_score?: ?float, vector_score?: ?float}  $top
     * @param  array{below_min_sim: int, brand: int, article: int}  $filterReasons
     */
    private function buildRow(RequestItem $item, array $top, string $itemBrandRaw, string $bucket, ?string $llmVerdict, ?string $llmReason, array $filterReasons, int $safeCount, int $allCount): array
    {
        $catalog = $top['catalog'];
        return [
            'item_id' => $item->id,
            'item_brand' => $itemBrandRaw,
            'item_name' => (string) $item->parsed_name,
            'item_article' => (string) $item->parsed_article,
            'src' => $item->image_attachment_id !== null ? 'photo' : 'text',
            'sim' => (float) $top['similarity'],
            'method' => (string) ($top['method'] ?? 'vector'),
            'code_score' => isset($top['code_score']) ? (float) $top['code_score'] : null,
            'trgm_score' => isset($top['trgm_score']) ? (float) $top['trgm_score'] : null,
            'vector_score' => isset($top['vector_score']) ? (float) $top['vector_score'] : null,
            'cat_id' => $catalog->id,
            'cat_sku' => (string) $catalog->sku,
            'cat_brand' => (string) $catalog->brand,
            'cat_name' => (string) $catalog->name,
            'cat_article' => (string) $catalog->brand_article,
            'bucket' => $bucket,
            'llm' => $llmVerdict,
            'llm_reason' => $llmReason,
            'safe' => $safeCount,
            'all' => $allCount,
            'filter' => $filterReasons,
        ];
    }

    private function printOne(array $r): void
    {
        $color = match (true) {
            str_starts_with($r['bucket'], 'would_match') || str_starts_with($r['bucket'], 'rerank_picked') => 'green',
            str_starts_with($r['bucket'], 'binary_llm_rejected') || str_starts_with($r['bucket'], 'rerank_rejected_all') => 'red',
            str_starts_with($r['bucket'], 'binary_llm_failed') || str_starts_with($r['bucket'], 'rerank_failed') => 'magenta',
            str_starts_with($r['bucket'], 'filtered') => 'yellow',
            default => 'white',
        };

        $subs = [];
        if ($r['code_score'] !== null) $subs[] = sprintf('code=%.2f', $r['code_score']);
        if ($r['trgm_score'] !== null) $subs[] = sprintf('trgm=%.2f', $r['trgm_score']);
        if ($r['vector_score'] !== null) $subs[] = sprintf('vec=%.2f', $r['vector_score']);
        $subsStr = $subs === [] ? '' : '  [' . implode(' ', $subs) . ']';

        $f = $r['filter'];
        $filtStr = sprintf('safe=%d/%d  (filt: brand=%d,art=%d,sim=%d)',
            $r['safe'], $r['all'], $f['brand'], $f['article'], $f['below_min_sim']);

        $this->line('');
        $this->line(sprintf(
            '<fg=%s>#%d  src=%s  sim=%.3f  via=%s%s  %s  ⇒ %s</>',
            $color, $r['item_id'], $r['src'], $r['sim'], $r['method'] ?? '?',
            $subsStr, $filtStr, $r['bucket'],
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
            $this->line('  LLM: ' . mb_substr($r['llm_reason'], 0, 250));
        }
    }
}
