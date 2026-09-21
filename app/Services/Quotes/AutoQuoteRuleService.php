<?php

namespace App\Services\Quotes;

use App\Enums\MailDirection;
use App\Enums\MatchPath;
use App\Models\EmailMessage;
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
        $lines = $this->lines($items, $this->discountFor($request));
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
        $checks[] = $this->check(
            'known_client',
            'Клиент не новый',
            $this->clientIsKnown($request),
            'первому обращению отвечает человек',
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
        ];
    }

    /**
     * Что автомат поставил бы в КП.
     *
     * Скидку берём ту же, что подставилась бы в ручное КП, — из карточки
     * организации, и через ту же формулу `MAX(цена × (1 − скидка), цена_мин)`.
     * Иначе автомат выставляет каталожную цену там, где менеджер даёт
     * привычные клиенту минус двадцать процентов: на холостом прогоне это
     * сразу дало медиану расхождения ровно 1,25.
     *
     * @param  \Illuminate\Support\Collection<int, RequestItem>  $items
     * @return array<int, array<string, mixed>>
     */
    public function lines($items, float $discountPercent = 0.0): array
    {
        $out = [];
        foreach ($items as $item) {
            $catalog = $item->catalogItem;
            $qty = (float) $item->parsed_qty;
            $catalogPrice = (float) ($catalog?->price ?? 0);
            $price = $this->quotations->computeFinalUnitPrice(
                $catalogPrice,
                $catalog?->price_min !== null ? (float) $catalog->price_min : null,
                $discountPercent,
            );

            $out[] = [
                'request_item_id' => $item->id,
                'sku' => (string) ($catalog?->sku ?? ''),
                'name' => (string) ($catalog?->name ?? $item->parsed_name),
                'asked' => trim((string) ($item->parsed_article ?: $item->parsed_name)),
                'qty' => $qty,
                'unit' => (string) ($item->parsed_unit ?: 'шт.'),
                'catalog_price' => $catalogPrice,
                'discount_percent' => $discountPercent,
                'unit_price' => $price,
                'total' => round($price * max($qty, 0), 2),
                'price_actual' => (bool) ($catalog?->is_price_actual ?? false),
                'stock' => (int) ($catalog?->stock_available ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Скидка клиента: карточка организации, а если там пусто — выгрузка скидок
     * из корпоративной базы по ИНН. Та же скидка подставляется в ручное КП.
     */
    public function discountFor(Request $request): float
    {
        return $this->discounts->discountFor($request->organization);
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
