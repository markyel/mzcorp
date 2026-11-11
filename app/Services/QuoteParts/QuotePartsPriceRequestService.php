<?php

namespace App\Services\QuoteParts;

use App\Models\CatalogItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Supplier\SupplierMatchService;
use App\Services\Supplier\SupplierProcurementDispatchService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Запрос цен по пачке каталожных позиций из раздела «QuoteParts» (11.11.2026): актуализация цен,
 * количество не обязательно — пусто значит «нужна актуальная цена за штуку».
 *
 * Отправка — как у «Снабжения»: системным поставщикам — запрос платформы (письмо с
 * myzip@quoteparts.net и форма), внесистемным — наше письмо через QuoteParts с orders@.
 * С ящика менеджера — никогда: выключена маршрутизация — не отправлено.
 */
class QuotePartsPriceRequestService
{
    public function __construct(
        private readonly QuotePartsRfqService $rfqs,
        private readonly QuotePartsRouting $routing,
        private readonly SupplierProcurementDispatchService $letters,
    ) {}

    /**
     * @param  array<int, float|null>  $quantities  catalog_item_id => количество; null — без количества
     * @param  list<int>  $supplierIds
     * @return array{tags: list<string>, errors: list<string>, sent: int}
     */
    public function send(array $quantities, array $supplierIds, ?string $replyBy, ?string $note, User $by): array
    {
        $out = ['tags' => [], 'errors' => [], 'sent' => 0];
        $route = $this->routing->split($supplierIds);
        if ($route['mail'] !== []) {
            $out['errors'][] = 'Отправка через QuoteParts выключена — запрос не отправлен.';

            return $out;
        }

        if ($route['quoteparts'] !== []) {
            try {
                $out['tags'][] = $this->rfqs->send(null, $quantities, $route['quoteparts'], $replyBy, $note, $by, [],
                    routed: true, explicitSupplierIds: $this->explicitAddresses($route['quoteparts']))->tag;
                $out['sent'] += count($route['quoteparts']);
            } catch (Throwable $e) {
                Log::warning('QuoteParts price request refused', ['offsystem' => false, 'error' => $e->getMessage()]);
                $out['errors'][] = 'Системным поставщикам не отправлено: '.mb_substr($e->getMessage(), 0, 300);
            }
        }

        if ($route['offsystem'] !== []) {
            $catalog = CatalogItem::query()->whereIn('id', array_keys($quantities))
                ->get(['id', 'sku', 'name', 'name_en', 'brand', 'brand_article', 'articles']);
            $edits = [
                'qty' => array_map(fn ($q) => $q === null ? '' : self::number($q).' шт.', $quantities),
                'qty_en' => array_map(fn ($q) => $q === null ? '' : self::number($q).' pcs', $quantities),
            ];
            $letters = [];
            foreach (Supplier::query()->whereIn('id', $route['offsystem'])->get() as $s) {
                $letters[(int) $s->id] = $this->letters->composeLetter($catalog, $s, $note, $edits);
            }
            try {
                $out['tags'][] = $this->rfqs->send(null, $quantities, $route['offsystem'], $replyBy, $note, $by, [],
                    routed: true, letters: $letters, explicitSupplierIds: $this->explicitAddresses($route['offsystem']))->tag;
                $out['sent'] += count($route['offsystem']);
            } catch (Throwable $e) {
                Log::warning('QuoteParts price request refused', ['offsystem' => true, 'error' => $e->getMessage()]);
                $out['errors'][] = 'Внесистемным поставщикам (orders@) не отправлено: '.mb_substr($e->getMessage(), 0, 300);
            }
        }

        return $out;
    }

    /**
     * Отмечен один представитель поставщика — получателя выбирает QuoteParts (контакт «Расценка»);
     * иначе — отмеченные адреса. Так же решает «Снабжение» (Procurement\Index::routeToQuoteParts).
     *
     * @param  list<int>  $supplierIds
     * @return list<int>
     */
    private function explicitAddresses(array $supplierIds): array
    {
        $explicit = [];
        foreach (Supplier::query()->whereIn('id', $supplierIds)->get(['id', 'supplier_organization_id'])->groupBy(fn ($s) => (int) $s->supplier_organization_id) as $orgId => $g) {
            $ids = $g->pluck('id')->map(fn ($v) => (int) $v)->all();
            if ($orgId === 0 || $ids !== [SupplierMatchService::representativeId($orgId)]) {
                array_push($explicit, ...$ids);
            }
        }

        return $explicit;
    }

    private static function number(float $q): string
    {
        return rtrim(rtrim(number_format($q, 3, ',', ''), '0'), ',');
    }
}
