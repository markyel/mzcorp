<?php

namespace App\Services\Quotes;

use App\Enums\RequestStatus;
use App\Models\Request;
use App\Models\RequestItem;
use App\Services\Request\RequestStateService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * «Частичное КП» (18.11.2026): покрыты ли позиции заявки отправленными КП — из 1С (outbound_quotes, строки
 * сопоставлены OutboundQuoteItemMatcher) и собранными в mzCorp (Quotation, sent). Сколько бы КП ни ушло, считаем по
 * всем вместе.
 *
 * Активная позиция покрыта, если:
 *   — строка КП сопоставлена с ней;
 *   — строка КП с тем же товаром каталога (карточку позиции проставили после разбора КП — MZ2026-01469);
 *   — менеджер отметил «закрыто заменой» / «мы отказали» (request_items.quote_resolution);
 *   — замена: непокрытых позиций не больше, чем «лишних» строк КП, не закрывших ни одной позиции (предложили другой
 *     артикул: поручень M14760 вместо запрошенного — MZ2026-01343).
 * Строки доставки и упаковки не в счёт. Позиция, которую клиенту не нужно, — неактивна (is_active = false).
 *
 * Хоть одна позиция не покрыта → «Частичное КП», всё покрыто → «КП отправлено» (только между этими двумя статусами).
 * На 18.11.2026: из 839 заявок с КП за 45 дней частичных по этому правилу 100 (в лоб было 224).
 */
class QuoteCoverageService
{
    public const RESOLUTION_SUBSTITUTE = 'substitute';

    public const RESOLUTION_DECLINED = 'declined';

    public const RESOLUTIONS = [self::RESOLUTION_SUBSTITUTE => 'закрыто заменой', self::RESOLUTION_DECLINED => 'мы отказали'];

    /** Счёт по частичному КП оплачен — позиция ушла в заявку-остаток (PartialQuoteRemainderService); менеджер её не ставит. */
    public const RESOLUTION_CARRIED_OVER = 'carried_over';

    public const LABELS = self::RESOLUTIONS + [self::RESOLUTION_CARRIED_OVER => 'перенесено в заявку-остаток'];

    /** Служебные строки КП: не товар. */
    private const SERVICE_LINE = '/^\s*(доставка|упаковка|транспорт|экспресс|экспедир|курьер)/iu';

    /**
     * Позиции заявки, которые не товар: доставка, вписанная клиентом строкой, и «Бонусная карта №…» из подписи
     * клиента, принятая парсером за позицию (MZ2026-01114, M-2026-3076). КП на них не дают — непокрытыми не считаем.
     */
    private const NOT_GOODS_ITEM = '/^\s*(доставка|упаковка|транспорт|экспедир|курьер)|бонусн\p{L}*\s+карт/iu';

    private static bool $syncing = false;

    /** Статусы, между которыми покрытие переключает саму заявку. */
    public const QUOTE_STATUSES = [RequestStatus::Quoted, RequestStatus::PartiallyQuoted];

    /**
     * Заявка после КП в рабочем подсостоянии («Жду клиента», «В работе» на правках, «Отложена»): веха — КП
     * (полное или частичное), дальше сделка не ушла. Здесь покрытие переставляет веху, а не статус.
     */
    public static function followsQuote(Request $request): bool
    {
        return in_array($request->peak_status, self::QUOTE_STATUSES, true)
            && in_array($request->status, [RequestStatus::AwaitingClientClarification, RequestStatus::InProgress, RequestStatus::PostponedUntil], true);
    }

    /** Заявка в «Частичном КП» — статусом или вехой (после КП ждём клиента и т.п.). */
    public static function isPartial(Request $request): bool
    {
        return $request->status === RequestStatus::PartiallyQuoted
            || (self::followsQuote($request) && $request->peak_status === RequestStatus::PartiallyQuoted);
    }

    public function __construct(private readonly RequestStateService $states) {}

    /** Ушло ли по заявке хоть одно КП (из 1С с разобранными строками или собранное в mzCorp). */
    public function hasQuote(Request $request): bool
    {
        return $this->lines($request)->isNotEmpty() || $this->sentQuotationItemIds($request) !== [];
    }

    /**
     * Активные позиции, не покрытые ни одним отправленным КП.
     *
     * @return Collection<int, RequestItem>
     */
    public function uncovered(Request $request): Collection
    {
        $items = RequestItem::query()->where('request_id', $request->id)->where('is_active', true)->with('catalogItem:id,sku')
            ->orderBy('position')->orderBy('id')->get();

        return self::uncoveredOf($items, $this->lines($request), $this->sentQuotationItemIds($request));
    }

    /**
     * Правило покрытия — без базы, для тестов.
     *
     * @param  Collection<int, RequestItem>  $items  активные позиции
     * @param  Collection<int, object>  $lines  строки КП: raw_name, matched_request_item_id, matched_catalog_item_id
     * @param  list<int>  $quotedItemIds  позиции в отправленных КП mzCorp
     * @return Collection<int, RequestItem>
     */
    public static function uncoveredOf(Collection $items, Collection $lines, array $quotedItemIds = []): Collection
    {
        $lines = $lines->reject(fn ($l) => preg_match(self::SERVICE_LINE, (string) ($l->raw_name ?? '')) === 1)->values();

        // Наш M-артикул в строке КП (MZ2026-01220, 20.11.2026): менеджер завёл карточку M34817 под позицию «Кнопка -1»
        // и выдал КП, а разбор связал строку с соседней «Кнопкой 1». Строка с M-артикулом X покрывает позицию с
        // карточкой X — как только карточка в каталоге и у позиции. Связь строки с другой позицией НЕ отменяем: так
        // бывает и принадлежность (селектор к фотозавесе, MZ2026-01847), и ложное «КП отправлено» хуже ложного частичного.
        $itemSku = fn (RequestItem $i) => $i->relationLoaded('catalogItem') ? strtoupper((string) $i->catalogItem?->sku) : '';
        $lineSkus = $lines->map(fn ($l) => self::lineSku($l))->filter()->all();
        $byItem = $lines->pluck('matched_request_item_id')->filter()->map(fn ($id) => (int) $id)->merge($quotedItemIds)->all();
        $byCatalog = $lines->pluck('matched_catalog_item_id')->filter()->map(fn ($id) => (int) $id)->all();

        $open = $items->reject(fn (RequestItem $i) => $i->quote_resolution !== null
            || preg_match(self::NOT_GOODS_ITEM, (string) $i->parsed_name) === 1
            || in_array((int) $i->id, $byItem, true)
            || ($i->catalog_item_id !== null && in_array((int) $i->catalog_item_id, $byCatalog, true))
            || ($itemSku($i) !== '' && in_array($itemSku($i), $lineSkus, true)))->values();
        if ($open->isEmpty()) {
            return $open;
        }

        // «Лишние» строки: не закрыли ни одной позиции ни напрямую, ни по каталогу — предложенная замена.
        $itemIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
        $itemCatalog = $items->pluck('catalog_item_id')->filter()->map(fn ($id) => (int) $id)->all();
        $spare = $lines->filter(fn ($l) => ! in_array((int) ($l->matched_request_item_id ?? 0), $itemIds, true)
            && ! ($l->matched_catalog_item_id && in_array((int) $l->matched_catalog_item_id, $itemCatalog, true)))->count();

        return $open->count() <= $spare ? collect() : $open;
    }

    /** M-артикул строки КП (артикул, иначе в названии); кириллическая «М» — как латинская. */
    private static function lineSku(object $line): ?string
    {
        foreach ([(string) ($line->raw_article ?? ''), (string) ($line->raw_name ?? '')] as $text) {
            if (preg_match('/(?<![\p{L}\p{N}])[MМ](\d{4,6})(?![\p{L}\p{N}])/u', $text, $m) === 1) {
                return 'M'.$m[1];
            }
        }

        return null;
    }

    /**
     * Статус по покрытию: «КП отправлено» ↔ «Частичное КП». Другие статусы не трогает (клиент уже думает, счёт…),
     * без КП — тоже. Вызывают: разбор КП из 1С, переход в «КП отправлено», отметки менеджера, пересчёт при выкладке.
     */
    public function sync(Request $request, string $reason): ?RequestStatus
    {
        if (self::$syncing) {
            return null;
        }
        $request->refresh();
        $byStatus = in_array($request->status, self::QUOTE_STATUSES, true);
        if ((! $byStatus && ! self::followsQuote($request)) || ! $this->hasQuote($request)) {
            return null;
        }
        $open = $this->uncovered($request);
        $target = $open->isNotEmpty() ? RequestStatus::PartiallyQuoted : RequestStatus::Quoted;

        // После КП заявка ушла в «Жду клиента» / «В работе» / «Отложена»: статус — что делать сейчас, а чип
        // показывает веху (peak_status). Переставляем веху «КП отправлено» ↔ «Частичное КП», статус не трогаем
        // (MZ2026-01401: КП на одну позицию из двух, затем вопрос клиенту — в списке было «КП отправлено»).
        if (! $byStatus) {
            if ($request->peak_status === $target) {
                return null;
            }
            $request->forceFill(['peak_status' => $target->value])->saveQuietly();
            Log::info('QuoteCoverage: milestone synced', ['request_id' => $request->id, 'status' => $request->status->value, 'peak' => $target->value, 'reason' => $reason]);

            return $target;
        }
        if ($request->status === $target) {
            return null;
        }

        self::$syncing = true;
        try {
            $this->states->transitionTo($request, $target, null, [
                'event' => 'quote_coverage',
                'comment' => $target === RequestStatus::PartiallyQuoted
                    ? 'КП покрывает не все позиции — нет предложения по: '.$open->map(fn (RequestItem $i) => mb_strimwidth((string) $i->parsed_name, 0, 60, '…'))->implode('; ').'.'
                    : 'КП покрывает все позиции заявки.',
                'payload' => ['reason' => $reason, 'uncovered_item_ids' => $open->pluck('id')->all()],
                // Пересчёт — не новое событие: время последней активности не двигаем.
                'quiet_activity' => true,
            ], systemTransition: true);
        } catch (\Throwable $e) {
            Log::warning('QuoteCoverage: status sync failed', ['request_id' => $request->id, 'to' => $target->value, 'error' => $e->getMessage()]);

            return null;
        } finally {
            self::$syncing = false;
        }

        return $target;
    }

    /** Отметка менеджера по непокрытой позиции; null — снять. Статус пересчитывается. */
    public function resolve(RequestItem $item, ?string $resolution, ?int $userId): void
    {
        if ($resolution !== null && ! array_key_exists($resolution, self::RESOLUTIONS)) {
            throw new \DomainException('Неизвестная отметка позиции.');
        }
        $item->forceFill([
            'quote_resolution' => $resolution,
            'quote_resolved_by_user_id' => $resolution !== null ? $userId : null,
            'quote_resolved_at' => $resolution !== null ? now() : null,
        ])->save();
        if ($item->request !== null) {
            $this->sync($item->request, 'manager_resolution');
        }
    }

    public const PRICE_CATALOG = 'catalog';

    public const PRICE_SUPPLIER = 'supplier';

    public const PRICE_LABELS = [self::PRICE_CATALOG => 'цена в 1С актуальна', self::PRICE_SUPPLIER => 'поставщик назвал цену'];

    /**
     * Этап 3: у каких позиций без КП уже есть цена — можно дослать КП. Цена 1С (товар каталога, цена актуальна и
     * больше нуля) важнее ответа поставщика на наш запрос по этой позиции (supplier_offers, outcome = quoted).
     *
     * @param  Collection<int, RequestItem>  $items
     * @return array<int, string> id позиции => PRICE_CATALOG | PRICE_SUPPLIER
     */
    public function priceSources(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }
        $sources = [];

        $supplier = DB::table('supplier_offers as o')->join('supplier_inquiry_items as ii', 'ii.id', '=', 'o.supplier_inquiry_item_id')
            ->whereIn('ii.request_item_id', $items->pluck('id'))->where('o.outcome', 'quoted')->where('o.price', '>', 0)
            ->distinct()->pluck('ii.request_item_id');
        foreach ($supplier as $id) {
            $sources[(int) $id] = self::PRICE_SUPPLIER;
        }

        $catalogIds = $items->pluck('catalog_item_id')->filter()->unique();
        $actual = $catalogIds->isEmpty() ? collect() : DB::table('catalog_items')->whereIn('id', $catalogIds)
            ->where('is_price_actual', true)->where('price', '>', 0)->pluck('id')->map(fn ($id) => (int) $id);
        foreach ($items as $item) {
            if ($item->catalog_item_id !== null && $actual->contains((int) $item->catalog_item_id)) {
                $sources[(int) $item->id] = self::PRICE_CATALOG;
            }
        }

        return $sources;
    }

    /** @return Collection<int, object> строки отправленных КП из 1С по заявке */
    private function lines(Request $request): Collection
    {
        return DB::table('outbound_quote_items as l')->join('outbound_quotes as q', 'q.id', '=', 'l.outbound_quote_id')
            ->where('q.request_id', $request->id)->where('q.document_type', 'outbound_quotation_full')
            ->whereIn('q.status', ['matched', 'parsed'])
            ->get(['l.raw_name', 'l.raw_article', 'l.matched_request_item_id', 'l.matched_catalog_item_id']);
    }

    /** @return list<int> позиции в КП, собранных в mzCorp и отправленных клиенту */
    private function sentQuotationItemIds(Request $request): array
    {
        return DB::table('quotation_items as qi')->join('quotations as q', 'q.id', '=', 'qi.quotation_id')
            ->where('q.request_id', $request->id)->whereNotNull('q.sent_at')->whereNotNull('qi.request_item_id')
            ->pluck('qi.request_item_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
