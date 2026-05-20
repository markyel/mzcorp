<?php

namespace App\Services\Request;

use App\Enums\ComplexityLevel;
use App\Enums\MatchPath;
use App\Models\Request;
use App\Models\RequestItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Вычисление сложности заявки.
 *
 * Score = Σ MatchPath::defaultWeight() по всем active items.
 * Level выводится по порогам (AppSetting `complexity.thresholds`).
 *
 * Принцип «snapshot входной сложности»:
 *   - match_path присваивается при первом резолве в CatalogResolutionService
 *     (читаем `payload.catalog_match.method`)
 *   - после ручной правки менеджером (linkToCatalog → method=manual_link)
 *     match_path остаётся `MatchPath::Manual` — нас интересует «в каком виде
 *     заявка пришла», а не итоговое состояние
 *
 * Triggers (RequestItemObserver):
 *   - item created → detect match_path + recompute(request)
 *   - item updated → detect match_path (catalog_item_id / payload могли
 *     измениться) + recompute(request)
 *   - item deleted → recompute(request)
 */
class RequestComplexityService
{
    /**
     * Перечитать match_path позиции из payload и обновить in-memory + БД.
     * Не дёргает recompute родителя — это делает observer отдельно.
     *
     * Возвращает определённый MatchPath (для диагностики).
     */
    public function detectAndStoreItemPath(RequestItem $item): MatchPath
    {
        $path = MatchPath::detect(
            payload: $item->quality_assessment_payload,
            catalogItemId: $item->catalog_item_id,
            status: $item->quality_assessment_status?->value
                ?? (is_string($item->quality_assessment_status) ? $item->quality_assessment_status : null),
        );

        if ($item->match_path?->value !== $path->value) {
            // Прямой UPDATE — БЕЗ Eloquent save (чтобы observer не зациклился).
            DB::table('request_items')
                ->where('id', $item->id)
                ->update(['match_path' => $path->value]);
            $item->match_path = $path;
        }

        return $path;
    }

    /**
     * Пересчитать complexity_score + level для заявки.
     * Дёргается из observer после изменений items / руками из CLI.
     *
     * @return array{score: int, level: ComplexityLevel, items_count: int}
     */
    public function recompute(Request $request): array
    {
        $weights = $this->weights();
        $thresholds = $this->thresholds();

        // Берём только active items. paths из match_path (если null —
        // считаем как Manual, default безопасный).
        $rows = DB::table('request_items')
            ->select('match_path')
            ->where('request_id', $request->id)
            ->where('is_active', true)
            ->get();

        $score = 0;
        foreach ($rows as $row) {
            $path = $row->match_path ?: MatchPath::Manual->value;
            $score += $weights[$path] ?? MatchPath::Manual->defaultWeight();
        }

        $level = ComplexityLevel::fromScore($score, $thresholds);

        // Прямой UPDATE через Query Builder — без модели и observer'ов.
        // Если значения не изменились — DB всё равно делает UPDATE, но в
        // нашем случае это редкая операция (не каждый paint), приемлемо.
        DB::table('requests')
            ->where('id', $request->id)
            ->update([
                'complexity_score' => $score,
                'complexity_level' => $level->value,
            ]);

        // Sync in-memory model на случай если caller будет дальше работать с $request.
        $request->complexity_score = $score;
        $request->complexity_level = $level;

        return [
            'score' => $score,
            'level' => $level,
            'items_count' => $rows->count(),
        ];
    }

    /**
     * Backfill: пересчитать match_path по всем items + recompute по всем
     * Request. Используется из CLI `requests:recompute-complexity`.
     *
     * @return array{requests: int, items: int, by_level: array<string, int>}
     */
    public function backfillAll(): array
    {
        $itemsCount = 0;
        $byLevel = ['easy' => 0, 'normal' => 0, 'hard' => 0, 'very_hard' => 0];

        // (1) Пересчитать match_path по всем items, в чанках.
        RequestItem::query()
            ->select(['id', 'request_id', 'catalog_item_id', 'quality_assessment_status', 'quality_assessment_payload', 'match_path'])
            ->orderBy('id')
            ->chunkById(500, function ($items) use (&$itemsCount) {
                foreach ($items as $item) {
                    $this->detectAndStoreItemPath($item);
                    $itemsCount++;
                }
            });

        // (2) Recompute score+level по всем Request.
        $requestsCount = 0;
        Request::query()
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(200, function ($requests) use (&$requestsCount, &$byLevel) {
                foreach ($requests as $req) {
                    $result = $this->recompute($req);
                    $byLevel[$result['level']->value]++;
                    $requestsCount++;
                }
            });

        Log::info('RequestComplexityService: backfill done', [
            'requests' => $requestsCount,
            'items' => $itemsCount,
            'by_level' => $byLevel,
        ]);

        return [
            'requests' => $requestsCount,
            'items' => $itemsCount,
            'by_level' => $byLevel,
        ];
    }

    /**
     * Веса MatchPath из AppSetting (fallback на enum::defaultWeight).
     *
     * @return array<string, int>  match_path => weight
     */
    private function weights(): array
    {
        return [
            MatchPath::InternalSku->value => (int) app_setting('complexity.weights.internal_sku', MatchPath::InternalSku->defaultWeight()),
            MatchPath::BrandArticle->value => (int) app_setting('complexity.weights.brand_article', MatchPath::BrandArticle->defaultWeight()),
            MatchPath::NameMatch->value => (int) app_setting('complexity.weights.name_match', MatchPath::NameMatch->defaultWeight()),
            MatchPath::Manual->value => (int) app_setting('complexity.weights.manual', MatchPath::Manual->defaultWeight()),
        ];
    }

    /**
     * Пороги уровней из AppSetting (fallback на enum-default).
     *
     * @return array{easy_max: int, normal_max: int, hard_max: int}
     */
    private function thresholds(): array
    {
        $raw = app_setting('complexity.thresholds', ComplexityLevel::defaultThresholds());
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : ComplexityLevel::defaultThresholds();
        }
        return array_merge(ComplexityLevel::defaultThresholds(), is_array($raw) ? $raw : []);
    }
}
