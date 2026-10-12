<?php

namespace App\Services\Bot;

use App\Enums\OrganizationPricingMode;
use App\Models\CatalogItem;
use App\Models\ClientContact;
use App\Models\MessengerChat;
use App\Models\Organization;
use App\Services\Clients\ClientDiscountImportService;
use App\Services\Quotations\QuotationService;
use App\Services\Quotes\AutoQuoteRuleService;

/**
 * Цена позиции по M-артикулу для клиента бота.
 *
 * Решение заказчика (12.10.2026): цена организации клиента — как в авто-КП
 * (скидка, но не ниже минимальной; «себестоимость + наценка» для особых
 * клиентов). Организация — только та, к которой подтверждённый e-mail уже
 * привязан в реестре клиентов (сомнительные привязки ждут менеджера, см.
 * OrganizationLinkGuard); иначе розница. Неактуальную цену не называем.
 */
class BotPriceService
{
    /** M-артикул в тексте; кириллическую «М» клиенты пишут постоянно. */
    public const SKU_RE = '/(?<![\p{L}\d])[MМmм]\s?-?(\d{4,6})(?!\d)/u';

    /** Сколько артикулов из одного сообщения смотрим. */
    public const MAX_PER_MESSAGE = 10;

    public function __construct(
        private readonly QuotationService $quotations,
        private readonly ClientDiscountImportService $discounts,
    ) {}

    /** @return list<string> артикулы вида M12345 в порядке упоминания */
    public static function skusIn(string $text): array
    {
        if (! preg_match_all(self::SKU_RE, $text, $m)) {
            return [];
        }

        return array_slice(array_values(array_unique(array_map(fn ($d) => 'M'.$d, $m[1]))), 0, self::MAX_PER_MESSAGE);
    }

    /**
     * @return array{found: bool, sku: string, name: ?string, price: ?float, for_org: ?string,
     *     stock: float, lead_days: ?int, catalog_item_id: ?int}
     */
    public function quote(MessengerChat $chat, string $sku): array
    {
        $item = CatalogItem::query()->where('sku', $sku)->where('is_active', true)->first();
        if ($item === null) {
            return ['found' => false, 'sku' => $sku, 'name' => null, 'price' => null, 'for_org' => null, 'stock' => 0.0, 'lead_days' => null, 'catalog_item_id' => null];
        }

        $org = $this->organizationFor($chat);
        $price = null;
        if ($item->is_price_actual && (float) $item->price > 0) {
            if ($org?->pricing_mode === OrganizationPricingMode::CostPlus) {
                $purchase = AutoQuoteRuleService::purchasePrice($item);
                $markup = (float) config('services.pricing.cost_plus_markup', 15);
                $price = $purchase > 0 ? round($purchase * (1 + $markup / 100), 2) : (float) $item->price;
            } else {
                $price = $this->quotations->computeFinalUnitPrice(
                    (float) $item->price,
                    AutoQuoteRuleService::priceMin($item),
                    $this->discounts->discountFor($org),
                );
            }
        }

        return [
            'found' => true,
            'sku' => $sku,
            'name' => (string) $item->name,
            'price' => $price,
            'for_org' => $org !== null && $price !== null ? (string) $org->name : null,
            'stock' => (float) ($item->stock_available ?? 0),
            'lead_days' => $item->lead_time_days !== null ? (int) $item->lead_time_days : null,
            'catalog_item_id' => (int) $item->id,
        ];
    }

    /**
     * Организация клиента: закреплённая за адресом, иначе самая выгодная из
     * тех, к которым адрес привязан (то же правило, что в авто-КП).
     */
    public function organizationFor(MessengerChat $chat): ?Organization
    {
        if (! $chat->isVerified()) {
            return null;
        }
        $email = mb_strtolower((string) $chat->email);

        $pinnedId = ClientContact::pinnedOrganizationIdFor($email);
        if ($pinnedId !== null) {
            return Organization::find($pinnedId);
        }

        $candidates = Organization::query()
            ->whereHas('contacts', fn ($q) => $q->whereRaw('lower(email) = ?', [$email]))
            ->get();

        return $candidates->isEmpty()
            ? null
            : AutoQuoteRuleService::mostGenerous($candidates, fn (Organization $o) => $this->discounts->discountFor($o));
    }

    /** Строка ответа бота о позиции (HTML для мессенджера). */
    public static function format(array $q): string
    {
        if (! $q['found']) {
            return '<b>'.e($q['sku']).'</b> — такого артикула в каталоге нет.';
        }

        $lines = ['<b>'.e($q['sku']).'</b> — '.e((string) $q['name'])];
        $lines[] = $q['price'] !== null
            ? 'Цена: <b>'.number_format((float) $q['price'], 2, ',', ' ').' ₽</b> с НДС'
                .($q['for_org'] !== null ? ' (для '.e($q['for_org']).')' : '')
            : 'Цена: уточнит менеджер — оформите заявку.';
        $lines[] = $q['stock'] > 0
            ? 'Наличие: есть на складе'
            : 'Наличие: под заказ'.($q['lead_days'] ? ', срок ~'.$q['lead_days'].' дн.' : '');

        return implode("\n", $lines);
    }
}
