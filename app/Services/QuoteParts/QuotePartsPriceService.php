<?php

namespace App\Services\QuoteParts;

use App\Models\CatalogItem;
use App\Models\QuotePartsItemDecline;
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
        // Ответ на запрос: заявка — по нашему запросу (quoteparts_rfqs), иначе по коду заявки в external_ref.
        $rfqIds = collect($offers)->pluck('rfq.id')->filter()->unique()->values();
        $requestByRfq = $rfqIds->isEmpty() ? collect() : \App\Models\QuotePartsRfq::query()->whereIn('remote_id', $rfqIds)->whereNotNull('request_id')->pluck('request_id', 'remote_id');
        $refs = collect($offers)->pluck('rfq.external_ref')->filter()->unique()->values();
        $requestByRef = $refs->isEmpty() ? collect() : \App\Models\Request::query()->whereIn('internal_code', $refs)->pluck('id', 'internal_code');
        // Китайские поставщики пишут примечание, бренд и артикул аналога по-китайски — снабжению по-русски.
        $texts = collect($offers)->map(fn ($o) => self::offerTexts($o));
        $translated = app(ForeignTextTranslator::class)->toRussian($texts->flatten()->filter()->values()->all());

        foreach ($offers as $i => $o) {
            $tiers = (array) ($o['tiers'] ?? []);
            [$local, $originals] = self::localized($texts[$i], $translated);
            $row = QuotePartsOffer::query()->updateOrCreate(['remote_id' => (int) $o['id']], $local + [
                'original_texts' => $originals ?: null,
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
                // Цена агента — от какого реального поставщика (UniSystem: Sicor, Donati…).
                'origin_supplier' => isset($o['origin_supplier']) ? mb_substr((string) $o['origin_supplier'], 0, 150) : null,
                'photos' => (int) ($o['photos'] ?? 0),
                'rfq_remote_id' => $o['rfq']['id'] ?? null,
                'rfq_tag' => $o['rfq']['tag'] ?? null,
                'request_id' => isset($o['rfq']['id'])
                    ? ($requestByRfq[$o['rfq']['id']] ?? $requestByRef[$o['rfq']['external_ref'] ?? ''] ?? null)
                    : null,
            ]);
            // Не оригинал — на разбор снабжению; то же предложение, уже разобранное раньше (продлённое,
            // повторённое), наследует решение.
            if (($o['kind'] ?? 'original') !== 'original' && $row->review_status === null) {
                $row->forceFill(['review_status' => $this->earlierDecision($row) ?? QuotePartsOffer::REVIEW_PENDING])->save();
            }
        }

        return count($offers);
    }

    /**
     * Тексты оффера, которые пишет поставщик. Аналог, копия, замена — другой товар: чей и какой артикул.
     *
     * @return array{brand: ?string, part_number: ?string, product_name: ?string, note: ?string}
     */
    public static function offerTexts(array $o): array
    {
        return [
            'brand' => $o['product']['brand'] ?? null,
            'part_number' => $o['product']['part_number'] ?? null,
            'product_name' => $o['product']['name'] ?? null,
            'note' => isset($o['note']) ? mb_substr((string) $o['note'], 0, 1000) : null,
        ];
    }

    /**
     * Подставить русский перевод вместо китайского: [поля с переводом, оригиналы переведённых полей].
     * «无» (нет) в бренде аналога переводится в пусто — поля нет.
     *
     * @param  array<string, ?string>  $texts
     * @param  array<string, string>  $translated  исходный текст => перевод
     * @return array{0: array<string, ?string>, 1: array<string, string>}
     */
    public static function localized(array $texts, array $translated): array
    {
        $originals = [];
        foreach ($texts as $field => $text) {
            if ($text !== null && isset($translated[$text])) {
                $originals[$field] = $text;
                $texts[$field] = $translated[$text] !== '' ? $translated[$text] : null;
            }
        }

        return [$texts, $originals];
    }

    /** Решение по тому же варианту того же поставщика на эту позицию, если уже было. */
    private function earlierDecision(QuotePartsOffer $offer): ?string
    {
        return QuotePartsOffer::query()
            ->where('id', '!=', $offer->id)->where('sku', $offer->sku)->where('supplier_key', $offer->supplier_key)->where('kind', $offer->kind)
            ->whereRaw('lower(coalesce(part_number, \'\')) = ?', [mb_strtolower((string) $offer->part_number)])
            ->whereIn('review_status', [QuotePartsOffer::REVIEW_ACCEPTED, QuotePartsOffer::REVIEW_REJECTED])
            ->latest('reviewed_at')->value('review_status');
    }

    /**
     * Разбор отклонения: принять к закупке по запрошенной позиции (цена идёт как цена позиции)
     * или отклонить.
     */
    public function review(QuotePartsOffer $offer, bool $accept, User $by): QuotePartsOffer
    {
        abort_if(($offer->kind ?? 'original') === 'original', 422, 'Оригинал не требует разбора.');
        $offer->forceFill([
            'review_status' => $accept ? QuotePartsOffer::REVIEW_ACCEPTED : QuotePartsOffer::REVIEW_REJECTED,
            'reviewed_by_user_id' => $by->id,
            'reviewed_at' => now(),
        ])->save();

        return $offer;
    }

    /**
     * Комментарий к предложению в выгрузке 1С, поправленный снабжением. Совпадает с собранным
     * (или null) — снова собирается сам: поправки не было.
     */
    public function setExportComment(QuotePartsOffer $offer, ?string $text, User $by): QuotePartsOffer
    {
        $text = $text === null ? null : trim((string) preg_replace('/\s+/u', ' ', $text));
        $auto = (string) \App\Services\Procurement\PriceExportService::offerComment($offer);
        $own = $text === null || $text === $auto ? null : mb_substr($text, 0, 2000);
        $offer->forceFill(['export_comment' => $own, 'export_comment_by_user_id' => $own !== null ? $by->id : null])->save();

        return $offer;
    }

    /**
     * «Игнорировать» / «вернуть»: предложение не о том — его цена не идёт в выгрузку 1С, в «лучшее»
     * и в выбор закупки; если по нему уже выбрали «закупаем здесь», выбор снимается.
     */
    public function ignore(QuotePartsOffer $offer, bool $ignore, User $by): QuotePartsOffer
    {
        $offer->forceFill(['ignored_at' => $ignore ? now() : null, 'ignored_by_user_id' => $ignore ? $by->id : null])->save();
        if ($ignore && $offer->catalog_item_id !== null) {
            \App\Models\CatalogItemSupplierChoice::query()->where('catalog_item_id', $offer->catalog_item_id)
                ->where('source', 'quoteparts')->where('offer_ref', $offer->id)->delete();
        }

        return $offer;
    }

    /**
     * Копия отметок поставщиков «не сможем предложить» — изменения с последней синхронизации.
     *
     * @return int сколько записано
     */
    public function syncDeclines(?string $since = null): int
    {
        $since ??= QuotePartsItemDecline::query()->max('remote_updated_at');
        $declines = $this->client->itemDeclines($since ? \Illuminate\Support\Carbon::parse($since)->subMinute()->toIso8601String() : null);
        if ($declines === []) {
            return 0;
        }

        $names = app(QuotePartsRfqService::class)->supplierNames([(object) ['snapshot' => ['suppliers' => collect($declines)->map(fn ($d) => ['external_id' => $d['supplier'] ?? null])->all()]]]);
        $catalog = CatalogItem::query()->whereIn('sku', collect($declines)->pluck('item_code')->filter()->unique())->pluck('id', 'sku');

        foreach ($declines as $d) {
            QuotePartsItemDecline::query()->updateOrCreate(['remote_id' => (int) $d['id']], [
                'catalog_item_id' => $catalog[$d['item_code'] ?? ''] ?? null,
                'sku' => $d['item_code'] ?? null,
                'supplier_key' => $d['supplier'] ?? null,
                'supplier_name' => $names[$d['supplier'] ?? ''] ?? ($d['supplier'] ?? null),
                'reason' => $d['reason'] ?? null,
                'declined_at' => $d['declined_at'] ?? null,
                'restored_at' => $d['restored_at'] ?? null,
                'remote_updated_at' => $d['updated_at'] ?? now(),
            ]);
        }

        return count($declines);
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
