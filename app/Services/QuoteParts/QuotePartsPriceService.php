<?php

namespace App\Services\QuoteParts;

use App\Models\CatalogItem;
use App\Models\QuotePartsOffer;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Прайсы поставщиков через QuoteParts (шаг 5) со стороны mzcorp:
 *  - resolve(): QuoteParts спрашивает, какая это наша позиция (строки прайса, которых нет в
 *    пуле) — ищем по всему каталогу: M-артикул из строки (сверяя с артикулом поставщика),
 *    иначе артикул; найденное добавляем в пул, чтобы QuoteParts записал оффер;
 *  - syncOffers(): копия офферов QuoteParts для «Снабжения»;
 *  - решения снабжения по строкам «на проверку».
 */
class QuotePartsPriceService
{
    public function __construct(
        private readonly QuotePartsClient $client,
        private readonly QuotePartsPoolService $pool,
    ) {}

    /**
     * @param  list<array{ref:int, our_code:?string, part_number:?string, brand:?string, name:?string}>  $rows
     * @return list<array{ref:int, status:string, code:?string, candidates:list<string>, reason:?string}>
     */
    public function resolve(array $rows): array
    {
        $out = [];
        $toPool = [];
        foreach ($rows as $row) {
            $answer = $this->resolveRow($row);
            if ($answer['code'] !== null && ($id = CatalogItem::query()->where('sku', $answer['code'])->value('id')) !== null) {
                $toPool[] = (int) $id;
            }
            $out[] = $answer + ['ref' => (int) $row['ref']];
        }

        // QuoteParts пишет оффер только на позицию из пула: добавляем названные позиции.
        if ($toPool !== []) {
            try {
                $this->pool->ensureInPool(array_values(array_unique($toPool)), null);
            } catch (Throwable $e) {
                Log::warning('QuoteParts resolve: pool add failed', ['error' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /**
     * @param  array{our_code:?string, part_number:?string}  $row
     * @return array{status:string, code:?string, candidates:list<string>, reason:?string}
     */
    private function resolveRow(array $row): array
    {
        $variants = $this->variants((string) ($row['part_number'] ?? ''));

        $code = strtoupper(trim((string) ($row['our_code'] ?? '')));
        if ($code !== '' && ($item = CatalogItem::query()->where('sku', $code)->first()) !== null) {
            if ($variants === [] || $this->fits($item, $variants)) {
                return ['status' => 'exact', 'code' => $item->sku, 'candidates' => [], 'reason' => null];
            }

            return ['status' => 'check', 'code' => $item->sku, 'candidates' => [$item->sku],
                'reason' => 'артикул поставщика '.$row['part_number'].' не совпадает с карточкой '.$item->sku.' ('.($item->brand_article ?: '—').')'];
        }

        $found = $this->byPartNumber($variants);
        if ($found->count() === 1) {
            return ['status' => 'exact', 'code' => $found->first()->sku, 'candidates' => [], 'reason' => $code !== '' ? 'код '.$code.' не найден, позиция найдена по артикулу' : null];
        }
        if ($found->count() > 1) {
            return ['status' => 'check', 'code' => null, 'candidates' => $found->pluck('sku')->values()->all(),
                'reason' => 'артикул есть у нескольких позиций каталога: '.$found->pluck('sku')->implode(', ')];
        }

        return ['status' => 'none', 'code' => null, 'candidates' => [], 'reason' => 'в каталоге mzcorp не найдено'];
    }

    /** @param list<string> $variants */
    private function fits(CatalogItem $item, array $variants): bool
    {
        $known = collect(array_merge([$item->brand_article], (array) ($item->articles ?? [])))->map(fn ($a) => $this->norm((string) $a))->filter();
        if ($known->isEmpty()) {
            return true;
        }
        foreach ($variants as $v) {
            if ($known->contains(fn ($k) => $k === $v || (strlen($v) >= 6 && (str_contains($k, $v) || str_contains($v, $k))))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $variants
     * @return Collection<int, CatalogItem>
     */
    private function byPartNumber(array $variants): Collection
    {
        $raw = array_values(array_filter($variants, fn ($v) => strlen($v) >= 5));
        if ($raw === []) {
            return collect();
        }

        return CatalogItem::query()->where(function ($q) use ($raw) {
            foreach ($raw as $v) {
                $q->orWhereRaw("upper(regexp_replace(coalesce(brand_article, ''), '[^A-Za-z0-9]', '', 'g')) = ?", [$v]);
            }
        })->limit(5)->get(['id', 'sku', 'brand_article', 'articles'])
            ->merge($this->byArticlesJson($raw))->unique('id')->values();
    }

    /** @param list<string> $raw @return Collection<int, CatalogItem> */
    private function byArticlesJson(array $raw): Collection
    {
        return CatalogItem::query()->where(function ($q) use ($raw) {
            foreach ($raw as $v) {
                $q->orWhereJsonContains('articles', $v);
            }
        })->limit(5)->get(['id', 'sku', 'brand_article', 'articles']);
    }

    /** «17091017/1709101700», «591887 V10.09» → нормализованные варианты. @return list<string> */
    private function variants(string $partNumber): array
    {
        $out = [];
        foreach (preg_split('/[\/,;]+/', $partNumber) ?: [] as $p) {
            $out[] = $this->norm($p);
            $out[] = $this->norm((string) preg_replace('/\s+V?\d+(\.\d+)+$/i', '', $p));
        }

        return array_values(array_unique(array_filter($out)));
    }

    private function norm(string $s): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $s));
    }

    /**
     * Подтянуть изменившиеся офферы QuoteParts в копию для «Снабжения».
     *
     * @return int сколько записано
     */
    public function syncOffers(?string $since = null): int
    {
        $since ??= QuotePartsOffer::query()->max('remote_updated_at');
        $offers = $this->client->offers($since ? \Illuminate\Support\Carbon::parse($since)->subMinute()->toIso8601String() : null);
        if ($offers === []) {
            return 0;
        }

        $names = app(QuotePartsRfqService::class)->supplierNames([(object) ['snapshot' => ['suppliers' => collect($offers)->map(fn ($o) => ['external_id' => $o['supplier'] ?? null])->all()]]]);
        $catalog = CatalogItem::query()->whereIn('sku', collect($offers)->pluck('item_code')->filter()->unique())->pluck('id', 'sku');

        foreach ($offers as $o) {
            $tiers = (array) ($o['tiers'] ?? []);
            QuotePartsOffer::query()->updateOrCreate(['remote_id' => (int) $o['id']], [
                'catalog_item_id' => $catalog[$o['item_code'] ?? ''] ?? null,
                'sku' => $o['item_code'] ?? null,
                'supplier_key' => $o['supplier'] ?? null,
                'supplier_name' => $names[$o['supplier'] ?? ''] ?? ($o['supplier'] ?? null),
                'price' => $tiers[0]['price'] ?? null,
                'currency' => $o['currency'] ?? null,
                'tiers' => $tiers,
                'lead_time_days' => $o['lead_time_days'] ?? null,
                'valid_until' => $o['valid_until'] ?? null,
                'kind' => $o['kind'] ?? null,
                'source_type' => $o['source_type'] ?? null,
                'status' => $o['status'] ?? null,
                'superseded' => (bool) ($o['superseded'] ?? false),
                'remote_updated_at' => $o['updated_at'] ?? now(),
            ]);
        }

        return count($offers);
    }

    public function accept(int $priceListId, int $rowId, ?string $code, User $by): array
    {
        abort_unless(QuotePartsPilot::canConverse($by), 403);
        $result = $this->client->acceptPriceListRow($priceListId, $rowId, $code);
        $this->syncOffers();

        return $result;
    }

    public function reject(int $priceListId, int $rowId, User $by): array
    {
        abort_unless(QuotePartsPilot::canConverse($by), 403);

        return $this->client->rejectPriceListRow($priceListId, $rowId);
    }
}
