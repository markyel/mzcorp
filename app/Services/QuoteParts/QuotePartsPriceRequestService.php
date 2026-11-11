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
     * @param  array<int, string>  $oem  catalog_item_id => артикул для поставщика, как его поправил человек;
     *                                   нет ключа — артикул каталога (CatalogItem::oemForExternal)
     * @param  list<array{path:string, name:string, mime:string, size?:int}>  $files  фото и файлы — каждому поставщику
     * @return array{tags: list<string>, errors: list<string>, sent: int}
     */
    public function send(array $quantities, array $supplierIds, ?string $replyBy, ?string $note, User $by, array $oem = [], array $files = []): array
    {
        $out = ['tags' => [], 'errors' => [], 'sent' => 0];
        $route = $this->routing->split($supplierIds);
        if ($route['mail'] !== []) {
            $out['errors'][] = 'Отправка через QuoteParts выключена — запрос не отправлен.';

            return $out;
        }
        $catalog = CatalogItem::query()->whereIn('id', array_keys($quantities))
            ->get(['id', 'sku', 'name', 'name_en', 'brand', 'brand_article', 'articles']);

        if ($route['quoteparts'] !== []) {
            try {
                $out['tags'][] = $this->rfqs->send(null, $quantities, $route['quoteparts'], $replyBy, $note, $by, [],
                    routed: true, files: $files, notes: self::articleNotes($catalog, $oem),
                    explicitSupplierIds: $this->explicitAddresses($route['quoteparts']))->tag;
                $out['sent'] += count($route['quoteparts']);
            } catch (Throwable $e) {
                Log::warning('QuoteParts price request refused', ['offsystem' => false, 'error' => $e->getMessage()]);
                $out['errors'][] = 'Системным поставщикам не отправлено: '.mb_substr($e->getMessage(), 0, 300);
            }
        }

        if ($route['offsystem'] !== []) {
            $edits = [
                'qty' => array_map(fn ($q) => $q === null ? '' : self::number($q).' шт.', $quantities),
                'qty_en' => array_map(fn ($q) => $q === null ? '' : self::number($q).' pcs', $quantities),
                'oem' => $oem,
            ];
            $letters = [];
            foreach (Supplier::query()->whereIn('id', $route['offsystem'])->get() as $s) {
                $letters[(int) $s->id] = $this->letters->composeLetter($catalog, $s, $note, $edits);
            }
            try {
                $out['tags'][] = $this->rfqs->send(null, $quantities, $route['offsystem'], $replyBy, $note, $by, [],
                    routed: true, files: $files, letters: $letters, explicitSupplierIds: $this->explicitAddresses($route['offsystem']))->tag;
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

    /**
     * Артикул для поставщика, если он не тот, что в карточке QuoteParts (там brand_article), —
     * системному поставщику примечанием к позиции: карточку под один запрос не поменять, а в
     * письме платформы стоит артикул карточки. Совпадает с карточкой или стёрт — примечания нет.
     *
     * @param  \Illuminate\Support\Collection<int, CatalogItem>  $catalog
     * @param  array<int, string>  $oem
     * @return array<string, string> «c:{catalog_item_id}» => примечание
     */
    public static function articleNotes(\Illuminate\Support\Collection $catalog, array $oem): array
    {
        $norm = fn (?string $s): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $s)));
        $notes = [];
        foreach ($catalog as $ci) {
            if (! array_key_exists($ci->id, $oem)) {
                continue;
            }
            $edited = trim((string) $oem[$ci->id]);
            $card = (string) $ci->brand_article;
            if ($edited !== '' && ! in_array($norm($edited), [$norm($card), $norm(CatalogItem::externalArticle($card))], true)) {
                $notes['c:'.$ci->id] = 'Part No.: '.$edited;
            }
        }

        return $notes;
    }

    private static function number(float $q): string
    {
        return rtrim(rtrim(number_format($q, 3, ',', ''), '0'), ',');
    }
}
