<?php

namespace App\Services\Quotes;

use App\Enums\MailDirection;
use App\Enums\MatchPath;
use App\Enums\OrganizationPricingMode;
use App\Models\CatalogItem;
use App\Models\CatalogPriceChange;
use App\Models\EmailMessage;
use App\Models\Organization;
use App\Models\Request;
use App\Models\RequestItem;
use App\Services\Clients\ClientDiscountImportService;
use App\Services\Mail\PostSaleFulfillmentDetector;
use App\Services\Quotations\QuotationService;

/**
 * Правило автоматической выдачи КП: годится ли заявка на автомат и что именно
 * автомат выдал бы.
 *
 * Правило взято из разбора 10 525 заявок за 22.06–17.09.2026 (отчёт «Заявки под
 * автоматическое КП»): под него попадает ~411 заявок в месяц, на однострочных
 * система выбирает тот же артикул, что и менеджер, в 99,1% случаев.
 *
 * Считаем ПО ЗАЯВКЕ ЦЕЛИКОМ: не прошла хоть одна позиция — не проходит заявка.
 * Автоматика не должна отвечать «частично»: клиент получит половину ответа и
 * всё равно пойдёт к менеджеру, а мы потеряем право на второй заход.
 *
 * Счёт автомат не выставляет НИКОГДА. Явная просьба «выставьте счёт» уводит
 * заявку к человеку: по истории такие заканчиваются счётом в 76% случаев.
 *
 * Сервис ничего не создаёт и никуда не пишет — только считает вердикт.
 */
class AutoQuoteRuleService
{
    /** Потолок суммы: выше — только человек. */
    public const MAX_TOTAL = 100_000.0;

    /** Автомат работает только на однострочных заявках — там точность 99,1%. */
    public const MAX_LINES = 1;

    public function __construct(
        private readonly PostSaleFulfillmentDetector $postSale,
        private readonly QuotationService $quotations,
        private readonly ClientDiscountImportService $discounts,
    ) {}

    /**
     * Вердикт по заявке.
     *
     * @return array{eligible: bool, stopped_at: ?string, checks: array<int, array{key: string, label: string, ok: bool, detail: string}>, lines: array<int, array<string, mixed>>, total: float}
     */
    public function verdict(Request $request): array
    {
        $items = $request->items->filter(fn (RequestItem $i) => (bool) $i->is_active)->values();
        $organization = $this->organizationFor($request);
        $lines = $this->lines($items, $this->discounts->discountFor($organization), $organization);
        $total = array_sum(array_column($lines, 'total'));

        $checks = [];
        $checks[] = $this->check(
            'single_line',
            'Однострочная заявка',
            $items->count() > 0 && $items->count() <= self::MAX_LINES,
            'позиций: '.$items->count(),
        );
        $checks[] = $this->check(
            'matched',
            'Все позиции сматчены по M-артикулу',
            $items->count() > 0 && $items->every(fn ($i) => $i->catalog_item_id !== null
                && self::matchPath($i) === MatchPath::InternalSku),
            $this->matchPathsDetail($items),
        );
        $checks[] = $this->check(
            'article_in_text',
            'Артикул есть в тексте строки',
            $items->every(fn ($i) => self::articleInText($i)),
            'проверяем, что артикул не додуман матчингом',
        );
        $checks[] = $this->check(
            'client_wrote',
            'Артикул написал сам клиент',
            $items->every(fn ($i) => $this->clientWroteArticle($request, $i)),
            'не подставился из нашего же КП или счёта',
        );
        $checks[] = $this->check(
            'price_actual',
            'Цена актуальна на все позиции',
            $items->count() > 0 && $items->every(fn ($i) => (float) ($i->catalogItem?->price ?? 0) > 0
                && (bool) ($i->catalogItem?->is_price_actual ?? false)),
            $this->pricesDetail($items),
        );
        $checks[] = $this->check(
            'qty',
            'Количество указано',
            $items->every(fn ($i) => (float) $i->parsed_qty > 0),
            'без количества считать нечего',
        );
        $checks[] = $this->check(
            'no_notes',
            'Нет уточнений в позициях',
            $items->every(fn ($i) => trim((string) $i->supplier_note) === ''),
            'уточнение = вопрос, на который автомат не отвечает',
        );
        // Не проверка, а пометка. Первому обращению мгновенная цена нужнее
        // всего: новых клиентов приводит реклама, и весь смысл объявления —
        // «деталь в наличии, цену дадим сразу». Держать их в ручной очереди
        // значит платить за скорость и не давать её. Риска тут нет: цену
        // незнакомцу называем розничную, то есть верхнюю.
        $checks[] = $this->check(
            'known_client',
            'Клиент',
            true,
            $this->clientIsKnown($request) ? 'обращался раньше' : 'новый — отвечаем сразу',
        );
        // Не проверка, а пояснение: клиент опознан — считаем по его условиям,
        // не опознан — розница. Розница безопасна (это верхняя граница цены),
        // поэтому автомат из-за неё не останавливается.
        $checks[] = $this->check(
            'client_price_known',
            'Цена клиента',
            true,
            self::pricingLabel($organization, $this->discounts->discountFor($organization)),
        );
        $checks[] = $this->check(
            'no_invoice_request',
            'Счёт явно не просят',
            ! $this->asksForInvoice($request),
            'просьба о счёте уводит заявку к менеджеру',
        );
        $checks[] = $this->check(
            'sum_limit',
            'Сумма до '.number_format(self::MAX_TOTAL, 0, ',', ' ').' ₽',
            $total > 0 && $total <= self::MAX_TOTAL,
            number_format($total, 2, ',', ' ').' ₽',
        );

        $failed = array_values(array_filter($checks, fn ($c) => ! $c['ok']));

        return [
            'eligible' => $failed === [],
            'stopped_at' => $failed[0]['key'] ?? null,
            'checks' => $checks,
            'lines' => $lines,
            'total' => (float) $total,
            // Кем считали цену: опознанный клиент со своими условиями или
            // розница по каталогу.
            'organization' => $organization,
            'pricing' => self::pricingLabel($organization, $this->discounts->discountFor($organization)),
        ];
    }

    /**
     * Что автомат поставил бы в КП.
     *
     * Цену считаем ровно так же, как ручное КП, и режимов у неё два:
     *
     *   · стандартный — каталог минус скидка клиента, но не ниже `price_min`;
     *   · себестоимость + наценка — закупочная × (1 + наценка), БЕЗ пола и
     *     БЕЗ скидки (`OrganizationPricingMode::CostPlus`).
     *
     * Второй режим — не экзотика: у такого клиента каталожная цена завышена
     * вдвое (кейс Liftway: автомат ставил 1 060,28 там, где менеджер отправил
     * 812,88). Пропустить режим здесь значит систематически врать в цене
     * целому классу клиентов.
     *
     * @param  \Illuminate\Support\Collection<int, RequestItem>  $items
     * @return array<int, array<string, mixed>>
     */
    public function lines($items, float $discountPercent = 0.0, ?Organization $organization = null): array
    {
        $costPlus = $organization?->pricing_mode === OrganizationPricingMode::CostPlus;
        $markup = (float) config('services.pricing.cost_plus_markup', 15);

        $out = [];
        foreach ($items as $item) {
            $catalog = $item->catalogItem;
            $qty = (float) $item->parsed_qty;
            $catalogPrice = (float) ($catalog?->price ?? 0);
            $priceMin = self::priceMin($catalog);

            if ($costPlus) {
                // Нет себестоимости — падаем на каталожную, как и ручное КП:
                // уронить позицию в ноль хуже, чем назвать цену выше.
                $purchase = self::purchasePrice($catalog);
                $price = $purchase > 0 ? round($purchase * (1 + $markup / 100), 2) : $catalogPrice;
            } else {
                $price = $this->quotations->computeFinalUnitPrice($catalogPrice, $priceMin, $discountPercent);
            }

            $out[] = [
                'request_item_id' => $item->id,
                'sku' => (string) ($catalog?->sku ?? ''),
                'name' => (string) ($catalog?->name ?? $item->parsed_name),
                'asked' => trim((string) ($item->parsed_article ?: $item->parsed_name)),
                'qty' => $qty,
                'unit' => (string) ($item->parsed_unit ?: 'шт.'),
                'catalog_price' => $catalogPrice,
                'price_min' => $priceMin,
                'discount_percent' => $costPlus ? 0.0 : $discountPercent,
                // Как получилась цена — чтобы вопрос «откуда 534,59» закрывался
                // строкой в интерфейсе, а не запросом в базу.
                'pricing_mode' => $costPlus ? 'cost_plus' : 'standard',
                'markup_percent' => $costPlus ? $markup : null,
                'purchase_price' => $costPlus ? self::purchasePrice($catalog) : null,
                'unit_price' => $price,
                'total' => round($price * max($qty, 0), 2),
                'price_actual' => (bool) ($catalog?->is_price_actual ?? false),
                'stock' => (int) ($catalog?->stock_available ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Минимальная цена позиции — пол в формуле скидки.
     *
     * Если позицию загрузили выборочным списком колонок без `price_min`,
     * свойство молча равно null, пол не срабатывает и КП уходит ниже
     * минимальной цены (кейс M22546: 534,59 вместо 568,00). Деньги не должны
     * зависеть от того, какие колонки перечислил вызывающий — дочитываем.
     */
    public static function priceMin(?CatalogItem $catalog): ?float
    {
        if ($catalog === null) {
            return null;
        }
        if (! array_key_exists('price_min', $catalog->getAttributes())) {
            $catalog->setAttribute(
                'price_min',
                CatalogItem::query()->whereKey($catalog->getKey())->value('price_min'),
            );
        }

        return $catalog->price_min !== null ? (float) $catalog->price_min : null;
    }

    /**
     * Цена позиции на дату — по истории изменений каталога.
     *
     * Сравнивать сегодняшнюю цену с КП недельной давности бессмысленно: между
     * ними мог пройти импорт каталога, и «другая цена» окажется нашей же
     * переоценкой, а не ошибкой правила. Берём первое изменение ПОСЛЕ даты
     * документа — цена «до» него и действовала в тот момент.
     *
     * @return array{price: float, price_min: ?float, changed: bool}
     */
    public static function priceAt(string $sku, ?\DateTimeInterface $moment, float $currentPrice, ?float $currentMin): array
    {
        if ($moment === null) {
            return ['price' => $currentPrice, 'price_min' => $currentMin, 'changed' => false];
        }

        $change = CatalogPriceChange::query()
            ->where('sku', $sku)
            ->where('changed_at', '>', $moment)
            ->orderBy('changed_at')
            ->first(['old_price', 'old_price_min']);

        if ($change === null) {
            return ['price' => $currentPrice, 'price_min' => $currentMin, 'changed' => false];
        }

        // В переходе может меняться только одно из двух полей — недостающее
        // берём сегодняшнее, оно с тех пор и не менялось.
        $price = $change->old_price !== null ? (float) $change->old_price : $currentPrice;
        $min = $change->old_price_min !== null ? (float) $change->old_price_min : $currentMin;

        return [
            'price' => $price,
            'price_min' => $min,
            'changed' => abs($price - $currentPrice) > 0.005 || abs(($min ?? 0) - ($currentMin ?? 0)) > 0.005,
        ];
    }

    /** Закупочная цена позиции — база режима «себестоимость + наценка». */
    public static function purchasePrice(?CatalogItem $catalog): float
    {
        if ($catalog === null) {
            return 0.0;
        }
        if (! array_key_exists('purchase_price', $catalog->getAttributes())) {
            $catalog->setAttribute(
                'purchase_price',
                CatalogItem::query()->whereKey($catalog->getKey())->value('purchase_price'),
            );
        }

        return (float) ($catalog->purchase_price ?? 0);
    }

    /**
     * По каким условиям посчитана цена — одной строкой для интерфейса.
     *
     * Клиента не опознали — это не повод молчать: розница по каталогу и есть
     * цена для тех, у кого особых условий нет. Опознали — считаем по карточке.
     */
    public static function pricingLabel(?Organization $organization, float $discount): string
    {
        if ($organization === null) {
            return 'розница по каталогу — клиент не опознан';
        }
        if ($organization->pricing_mode === OrganizationPricingMode::CostPlus) {
            return $organization->name.' · себестоимость + наценка';
        }

        return $organization->name.($discount > 0
            ? ' · скидка '.rtrim(rtrim(number_format($discount, 2, ',', ' '), '0'), ',').'%'
            : ' · без скидки, розница');
    }

    /**
     * Организация клиента: привязка заявки, а если её нет — поиск по e-mail в
     * контактах организаций.
     *
     * Привязка проставляется не всегда (кейс M-2026-16470: заявка без
     * организации, хотя ООО «Ураллифтналадка» с её же адресом и скидкой 17% в
     * системе есть). Без организации мы не знаем цену клиента, а прайсовая
     * цена ему не годится — поэтому ищем, и если не нашли, автомат молчит.
     */
    public function organizationFor(Request $request): ?Organization
    {
        if ($request->organization !== null) {
            return $request->organization;
        }

        $email = mb_strtolower(trim((string) $request->client_email));
        if ($email === '') {
            return null;
        }

        $candidates = Organization::query()
            ->whereHas('contacts', fn ($q) => $q->whereRaw('lower(email) = ?', [$email]))
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Один адрес может быть заведён у нескольких организаций — это норма
        // (снабженец обслуживает несколько юрлиц). Клиент при этом не пишет, на
        // кого выставлять, и угадывать мы не будем: по решению заказчика берём
        // САМЫЕ ВЫГОДНЫЕ клиенту условия. Кейс M-2026-16261: контакт заведён у
        // ООО «ЗИПИС» (20%) и «ИНДУСТРИЯ СЕРВИСА» (15%) — даём 20%, как и
        // сделал менеджер. Ошибка в свою пользу тут дороже: клиент сравнит
        // цену с прошлым КП и уйдёт.
        return self::mostGenerous($candidates, fn (Organization $o) => $this->discounts->discountFor($o));
    }

    /**
     * Организация с лучшими для клиента условиями.
     *
     * «Себестоимость + наценка» считаем лучшим вариантом: этот режим для
     * особых клиентов и почти всегда даёт цену ниже каталожной со скидкой.
     *
     * @param  \Illuminate\Support\Collection<int, Organization>  $candidates
     * @param  callable(Organization): float  $discountOf
     */
    public static function mostGenerous($candidates, callable $discountOf): ?Organization
    {
        $costPlus = $candidates->first(
            fn (Organization $o) => $o->pricing_mode === OrganizationPricingMode::CostPlus,
        );
        if ($costPlus !== null) {
            return $costPlus;
        }

        return $candidates->sortByDesc($discountOf)->first();
    }

    /**
     * Скидка клиента: карточка организации, а если там пусто — выгрузка скидок
     * из корпоративной базы по ИНН. Та же скидка подставляется в ручное КП.
     *
     * В режиме «себестоимость + наценка» скидки нет вовсе — цена считается от
     * закупочной, и скидка в карточке игнорируется.
     */
    public function discountFor(Request $request): float
    {
        return $this->discounts->discountFor($this->organizationFor($request));
    }

    /**
     * Артикул действительно присутствует в тексте строки — защита от «матчинг
     * додумал». По разбору эта проверка отсекает полтора процента заявок.
     */
    public static function articleInText(RequestItem $item): bool
    {
        $sku = trim((string) ($item->catalogItem?->sku ?? ''));
        if ($sku === '') {
            return false;
        }

        $haystack = self::normalize($item->parsed_article.' '.$item->parsed_name);

        return $haystack !== '' && str_contains($haystack, self::normalize($sku));
    }

    /**
     * Артикул написал клиент, а не подставился из наших же документов.
     *
     * Позиция помнит письмо-источник; годится только входящее. По разбору эта
     * отсечка снимает 187 заявок, где система была бы уверена в том, что сама
     * же и предположила.
     */
    public function clientWroteArticle(Request $request, RequestItem $item): bool
    {
        $source = $item->source_email_message_id
            ? EmailMessage::query()->find($item->source_email_message_id, ['id', 'direction', 'subject', 'body_plain'])
            : null;

        if ($source === null) {
            // Источник не записан (исторические данные) — смотрим на первое
            // входящее письмо заявки.
            $source = EmailMessage::query()
                ->where('related_request_id', $request->id)
                ->where('direction', MailDirection::Inbound->value)
                ->orderBy('id')
                ->first(['id', 'direction', 'subject', 'body_plain']);
        }
        // direction — тоже кастованный enum, сравниваем по значению.
        $direction = $source?->direction instanceof MailDirection
            ? $source->direction
            : MailDirection::tryFrom((string) ($source?->direction ?? ''));

        if ($source === null || $direction !== MailDirection::Inbound) {
            return false;
        }

        $sku = self::normalize((string) ($item->catalogItem?->sku ?? ''));

        return $sku !== '' && str_contains(self::normalize($source->subject.' '.$source->body_plain), $sku);
    }

    /** Есть ли у клиента заявки старше этой. */
    public function clientIsKnown(Request $request): bool
    {
        $email = trim((string) $request->client_email);
        if ($email === '') {
            return false;
        }

        return Request::query()
            ->where('id', '!=', $request->id)
            ->where('created_at', '<', $request->created_at)
            ->where(function ($q) use ($request, $email) {
                $q->where('client_email', $email);
                if ($request->organization_id) {
                    $q->orWhere('organization_id', $request->organization_id);
                }
            })
            ->exists();
    }

    /** Просит ли клиент счёт прямым текстом — такую заявку автомат не трогает. */
    public function asksForInvoice(Request $request): bool
    {
        $inbound = EmailMessage::query()
            ->where('related_request_id', $request->id)
            ->where('direction', MailDirection::Inbound->value)
            ->where('is_draft', false)
            ->orderBy('id')
            ->get();

        foreach ($inbound as $message) {
            if ($this->postSale->requestsInvoiceToPay($message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Путь матчинга позиции. Поле приходит то enum'ом (каст модели), то
     * строкой (сырой запрос) — приводим к одному виду, чтобы сравнение не
     * зависело от того, как позицию достали.
     */
    public static function matchPath(RequestItem $item): ?MatchPath
    {
        $raw = $item->match_path;

        return $raw instanceof MatchPath ? $raw : MatchPath::tryFrom((string) $raw);
    }

    /** Сравнение артикулов без регистра и разделителей: «XO-508» = «xo 508». */
    public static function normalize(?string $value): string
    {
        $clean = mb_strtolower(trim((string) $value));

        return preg_replace('/[^a-z0-9а-яё]+/u', '', $clean) ?? '';
    }

    /**
     * @return array{key: string, label: string, ok: bool, detail: string}
     */
    private function check(string $key, string $label, bool $ok, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
    }

    /** @param  \Illuminate\Support\Collection<int, RequestItem>  $items */
    private function matchPathsDetail($items): string
    {
        $paths = $items->map(fn ($i) => self::matchPath($i)?->label() ?? 'не сматчена')
            ->unique()->values()->all();

        return $paths === [] ? 'позиций нет' : implode(', ', $paths);
    }

    /** @param  \Illuminate\Support\Collection<int, RequestItem>  $items */
    private function pricesDetail($items): string
    {
        $stale = $items->filter(fn ($i) => ! (bool) ($i->catalogItem?->is_price_actual ?? false))->count();

        return $stale === 0 ? 'все актуальны' : "неактуальных цен: {$stale}";
    }
}
