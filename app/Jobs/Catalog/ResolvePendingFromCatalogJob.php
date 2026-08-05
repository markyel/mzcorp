<?php

namespace App\Jobs\Catalog;

use App\Models\RequestItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Bulk-резолв `internal_catalog_pending` позиций после успешного
 * импорта каталога. Дёргается из CatalogImportController после
 * успешной транзакции CatalogImportService.
 *
 * **Phase rebuild**: вместо обработки всех items в одном job'е (что
 * раньше валилось по timeout=120s при тысячах items с C-stage
 * pgvector+LLM), теперь только chunk-dispatcher:
 *   1. chunkById(100) пробегает выборку pending items.
 *   2. Для каждой сотни id'шников dispatch отдельный
 *      ResolvePendingChunkJob, который и обрабатывает (≤120с на 100).
 *
 * Преимущества:
 *   - параллелизм между worker'ами (4 worker'а × 100 items = 400/раз);
 *   - memory не накапливается между chunk'ами (worker завершается,
 *     --memory=600 защищает);
 *   - retry на chunk'е изолирован — fail одной сотни не валит весь
 *     resolve;
 *   - этот job сам быстрый (только SELECT id + dispatch), укладывается
 *     в любой timeout.
 *
 * ShouldBeUnique окно 1 минута — если в эту минуту прилетели два
 * snapshot'а подряд, повторный resolve не запускается.
 */
class ResolvePendingFromCatalogJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 120; // dispatch'ер сам по себе быстрый — оставляем запас.

    // 2026-05-24: уменьшили с 100 до 50 после боевого теста — C-stage
    // (vector+LLM) стоит ~2-3с/item, 50 items × 3 = 150с укладывается
    // в timeout=300 ChunkJob'а с запасом. 100 валилось по timeout.
    private const CHUNK_SIZE = 50;

    /**
     * @param array<int, string>|null $changedSkus  SKU каталожных позиций,
     *   изменившихся по МАТЧИНГ-полям (созданы / переименованы / сменили
     *   артикул). null = полный ре-резолв всего бэклога (ручной/первичный
     *   импорт). Точечный режим гоняет только pending с этими артикулами —
     *   ценовые/стоковые апдейты матчинг не меняют, весь бэклог не трогаем.
     */
    public function __construct(public ?array $changedSkus = null)
    {
        // queue=catalog-resolve через onQueue(), а не public $queue —
        // PHP 8 trait composition Fatal на default-mismatch с Queueable.
        // См. SyncMailboxFolderJob::__construct.
        $this->onQueue('catalog-resolve');
    }

    public function uniqueId(): string
    {
        // Разные scope не должны дедупиться друг с другом в окне uniqueFor.
        return 'resolve-pending-from-catalog-'
            . ($this->changedSkus === null ? 'all' : md5(implode(',', $this->changedSkus)));
    }

    public function uniqueFor(): int
    {
        return 60;
    }

    public function handle(): void
    {
        $totalDispatched = 0;
        $chunks = 0;

        $query = RequestItem::query()
            ->where('is_active', true)
            ->whereNull('catalog_item_id')
            ->where(function ($q) {
                $q->where('quality_assessment_status', 'internal_catalog_pending')
                    ->orWhereNotNull('parsed_article')
                    ->orWhereNotNull('parsed_name');
            });

        // Точечный режим: только pending-позиции, чей артикул совпадает с
        // артикулами новых/переименованных SKU. Импорт меняет цены/сток сотнями
        // строк, но матчинг это не меняет — гонять весь бэклог (тысячи pending)
        // бессмысленно и грузит БД на часы (кейс 2026-08-05).
        if ($this->changedSkus !== null) {
            $tokens = $this->articleTokensFor($this->changedSkus);
            if ($tokens === []) {
                Log::info('ResolvePendingFromCatalogJob: no article tokens for changed SKUs — skip', [
                    'changed_skus' => count($this->changedSkus),
                ]);

                return;
            }
            $placeholders = implode(',', array_fill(0, count($tokens), '?'));
            $query->whereRaw(
                "UPPER(REGEXP_REPLACE(COALESCE(parsed_article, ''), '[\\s._/-]', '', 'g')) IN ($placeholders)",
                $tokens,
            );
        }

        $query->select('id')
            ->chunkById(self::CHUNK_SIZE, function ($items) use (&$totalDispatched, &$chunks) {
                $ids = $items->pluck('id')->all();
                if (! empty($ids)) {
                    ResolvePendingChunkJob::dispatch($ids);
                    $totalDispatched += count($ids);
                    $chunks++;
                }
            });

        Log::info('ResolvePendingFromCatalogJob: chunks dispatched', [
            'chunks' => $chunks,
            'total_items' => $totalDispatched,
            'chunk_size' => self::CHUNK_SIZE,
            'scoped' => $this->changedSkus !== null,
        ]);
    }

    /**
     * Нормализованные артикул-токены (sku / brand_article / articles[])
     * каталожных позиций по их SKU. По ним отбираем pending для точечного
     * резолва. Нормализация ДОЛЖНА совпадать с SQL-выражением в handle().
     *
     * @param  array<int, string>  $skus
     * @return array<int, string>
     */
    private function articleTokensFor(array $skus): array
    {
        $norm = static fn ($s) => mb_strtoupper((string) preg_replace('/[\s._\/-]+/u', '', trim((string) $s)));
        $tokens = [];
        \App\Models\CatalogItem::query()
            ->whereIn('sku', array_values(array_unique($skus)))
            ->get(['sku', 'brand_article', 'articles'])
            ->each(function ($ci) use (&$tokens, $norm) {
                foreach (array_merge([$ci->sku, $ci->brand_article], (array) ($ci->articles ?? [])) as $a) {
                    $n = $norm($a);
                    if ($n !== '') {
                        $tokens[$n] = true;
                    }
                }
            });

        return array_keys($tokens);
    }
}
