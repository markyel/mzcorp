<?php

namespace App\Services\Catalog;

use App\Models\RequestItem;
use App\Services\AI\OpenAIEmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * «Как клиенты называли эту позицию» — подтверждённые формулировки клиентов к карточкам каталога
 * (таблица catalog_client_phrases).
 *
 * Подбор по названию берёт из них дополнительных кандидатов (клиенты пишут деталь своими словами, а каталог
 * 1С — по-своему) и показывает модели-реранку как примеры. Подтверждённая — позиция, чью карточку дал наш
 * M-артикул, артикул производителя, КП менеджера или ручная привязка, либо автоподбор, который потом
 * подтвердило КП той же заявки. Каталог 1С не трогаем.
 */
class CatalogClientPhraseService
{
    /** Способы привязки, которым верим как подтверждению. */
    public const CONFIRMED_METHODS = ['A_internal_sku', 'B_brand_article', 'outbound_quote', 'manual_link', 'own_quote_seed'];

    public function __construct(
        private readonly CatalogEmbeddingService $catalog,
        private readonly OpenAIEmbeddingService $embedder,
    ) {}

    /**
     * Догнать словарь: новые подтверждённые позиции — внести, позиции, у которых карточка сменилась или
     * подтверждение пропало, — убрать. Идемпотентно.
     *
     * @return array{added: int, removed: int}
     */
    public function sync(int $limit = 2000): array
    {
        $removed = DB::table('catalog_client_phrases as p')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('request_items as ri')
                ->whereColumn('ri.id', 'p.request_item_id')->whereColumn('ri.catalog_item_id', 'p.catalog_item_id')->where('ri.is_active', true))
            ->delete();

        $items = $this->confirmedQuery()
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('catalog_client_phrases as p')->whereColumn('p.request_item_id', 'request_items.id'))
            ->orderBy('request_items.id')
            ->limit($limit)
            ->get();

        $added = 0;
        foreach ($items->chunk(100) as $chunk) {
            $texts = $chunk->map(fn (RequestItem $i) => mb_substr($this->catalog->buildQueryText($i), 0, 2000))->values()->all();
            try {
                $vectors = $this->embedder->embedBatch($texts)['embeddings'];
            } catch (\Throwable $e) {
                Log::warning('CatalogClientPhraseService: embed failed, retry next run', ['error' => $e->getMessage()]);
                break;
            }
            foreach ($chunk->values() as $k => $item) {
                if (empty($vectors[$k])) {
                    continue;
                }
                DB::statement(
                    'insert into catalog_client_phrases (catalog_item_id, request_item_id, request_id, phrase, query_text, source, embedding, created_at, updated_at)
                     values (?, ?, ?, ?, ?, ?, ?::vector, now(), now()) on conflict (request_item_id) do nothing',
                    [$item->catalog_item_id, $item->id, $item->request_id, mb_substr((string) $item->parsed_name, 0, 500), $texts[$k],
                        $this->sourceOf($item), self::vectorLiteral($vectors[$k])],
                );
                $added++;
            }
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * Карточки, чьи прошлые формулировки ближе всего к запросу: catalog_item_id → [sim, phrase], лучшая на карточку.
     * Формулировки той же заявки не берём (её же позиции подсказывали бы сами себе).
     *
     * @return array<int, array{sim: float, phrase: string}>
     */
    public function nearestCatalogs(string $vectorLiteral, ?int $excludeRequestId, float $minSimilarity, int $limit = 5): array
    {
        $before = config('services.catalog_name_match.phrases_before_item_id');
        $rows = DB::select(
            'select p.catalog_item_id, p.phrase, 1 - (p.embedding <=> ?::vector) as sim
             from catalog_client_phrases p join catalog_items ci on ci.id = p.catalog_item_id and ci.is_active = true
             where p.request_id <> ? and (?::bigint is null or p.request_item_id < ?::bigint)
             order by p.embedding <=> ?::vector limit 40',
            [$vectorLiteral, (int) $excludeRequestId, $before, $before, $vectorLiteral],
        );

        $out = [];
        foreach ($rows as $r) {
            $sim = (float) $r->sim;
            if ($sim < $minSimilarity || isset($out[(int) $r->catalog_item_id])) {
                continue;
            }
            $out[(int) $r->catalog_item_id] = ['sim' => $sim, 'phrase' => (string) $r->phrase];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Примеры формулировок к карточкам — для подсказки модели-реранку.
     *
     * @param  list<int>  $catalogIds
     * @return array<int, list<string>>
     */
    public function examples(array $catalogIds, ?int $excludeRequestId, int $perCatalog = 3): array
    {
        if ($catalogIds === []) {
            return [];
        }
        $before = config('services.catalog_name_match.phrases_before_item_id');
        $rows = DB::table('catalog_client_phrases')
            ->whereIn('catalog_item_id', $catalogIds)
            ->where('request_id', '<>', (int) $excludeRequestId)
            ->when($before !== null, fn ($q) => $q->where('request_item_id', '<', (int) $before))
            ->orderByDesc('request_item_id')
            ->get(['catalog_item_id', 'phrase']);

        $out = [];
        foreach ($rows as $r) {
            $list = $out[(int) $r->catalog_item_id] ?? [];
            $phrase = trim((string) $r->phrase);
            if ($phrase === '' || count($list) >= $perCatalog || in_array(mb_strtolower($phrase), array_map('mb_strtolower', $list), true)) {
                continue;
            }
            $list[] = mb_substr($phrase, 0, 120);
            $out[(int) $r->catalog_item_id] = $list;
        }

        return $out;
    }

    /** Подтверждённые позиции: карточку дал точный код, КП или менеджер — либо автоподбор, подтверждённый КП. */
    public function confirmedQuery()
    {
        return RequestItem::query()
            ->whereNotNull('request_items.catalog_item_id')
            ->where('request_items.is_active', true)
            ->whereRaw("length(coalesce(request_items.parsed_name, '')) >= 5")
            ->where(function ($q) {
                $q->whereRaw("request_items.quality_assessment_payload->'catalog_match'->>'method' in ('".implode("','", self::CONFIRMED_METHODS)."')")
                    ->orWhere(fn ($w) => $w->whereRaw("request_items.quality_assessment_payload->'catalog_match'->>'method' in ('C_name_vector', 'C_exact_name')")
                        ->whereExists(fn ($e) => $e->selectRaw('1')->from('outbound_quote_items as l')
                            ->join('outbound_quotes as q', 'q.id', '=', 'l.outbound_quote_id')
                            ->whereColumn('q.request_id', 'request_items.request_id')
                            ->whereColumn('l.matched_catalog_item_id', 'request_items.catalog_item_id')));
            });
    }

    private function sourceOf(RequestItem $item): string
    {
        $method = (string) (($item->quality_assessment_payload ?? [])['catalog_match']['method'] ?? '');

        return in_array($method, self::CONFIRMED_METHODS, true) ? $method : 'confirmed_by_quote';
    }

    /** @param  list<float>  $vector */
    public static function vectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(fn ($v) => is_finite((float) $v) ? sprintf('%.7f', $v) : '0', $vector)).']';
    }
}
