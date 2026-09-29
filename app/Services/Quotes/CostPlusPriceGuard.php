<?php

namespace App\Services\Quotes;

use App\Enums\OrganizationPricingMode;
use App\Mail\CostPlusOverpriceMail;
use App\Models\CatalogItem;
use App\Models\EmailMessage;
use App\Models\Organization;
use App\Models\OutboundQuote;
use App\Models\Request;
use App\Models\User;
use App\Services\Mail\SystemNotificationMailer;
use Illuminate\Support\Facades\Log;

/**
 * Сторож цены для покупателей в режиме «закупка + наценка» (Liftway).
 *
 * КП и счета менеджеры делают в 1С, помешать заранее мы не можем, но видим
 * распознанный документ сразу после отправки. Если цена строки выше
 * «закупка × (1 + наценка)» больше допуска — письмо тому, кто документ
 * отправил (иначе менеджеру заявки), пока клиент не ответил. РОПу не пишем
 * (решение заказчика, 29.09.2026). По истории: 85 строк КП Liftway из 1 700
 * ушли выше режима, самые крупные — по полной цене каталога.
 *
 * Ниже режима — не сигнал: скидку сверх режима менеджер вправе дать сам.
 */
class CostPlusPriceGuard
{
    public function __construct(
        private readonly SystemNotificationMailer $mailer,
    ) {}

    /**
     * Проверить документ; письмо — не больше одного на документ.
     *
     * @return list<array{sku: string, name: string, qty: float, price: float, expected: float, purchase: float}>|null  завышенные строки; null — проверка не применима
     */
    public function check(OutboundQuote $quote, Request $request, bool $notify = true): ?array
    {
        $organization = $this->buyer($quote, $request);
        if ($organization === null || $organization->pricing_mode !== OrganizationPricingMode::CostPlus) {
            return null;
        }

        $markup = (float) config('services.pricing.cost_plus_markup', 15);
        $tolerance = (float) config('services.pricing.cost_plus_guard_tolerance', 0.02);

        $over = [];
        foreach ($quote->items()->get() as $item) {
            $catalog = $item->matched_catalog_item_id ? CatalogItem::query()->find($item->matched_catalog_item_id) : null;
            $purchase = AutoQuoteRuleService::purchasePrice($catalog);
            $price = (float) $item->unit_price;
            if ($purchase <= 0 || $price <= 0) {
                continue;
            }
            $expected = round($purchase * (1 + $markup / 100), 2);
            if ($price > $expected * (1 + $tolerance)) {
                $over[] = [
                    'sku' => (string) $catalog->sku,
                    'name' => (string) ($item->raw_name ?: $catalog->name),
                    'qty' => (float) ($item->quantity ?: 1),
                    'price' => $price,
                    'expected' => $expected,
                    'purchase' => $purchase,
                ];
            }
        }

        $overSum = array_sum(array_map(fn ($l) => ($l['price'] - $l['expected']) * $l['qty'], $over));
        $tooSmall = $overSum < (float) config('services.pricing.cost_plus_guard_min_rub', 500);
        if ($over === [] || $tooSmall || ! $notify || ! $this->fresh($quote) || $this->alreadyNotified($quote)) {
            return $over;
        }

        $user = $this->responsible($quote, $request);
        if ($user === null || trim((string) $user->email) === '') {
            Log::warning('CostPlusPriceGuard: nobody to notify', ['quote_id' => $quote->id, 'request_id' => $request->id]);

            return $over;
        }

        try {
            $this->mailer->sendMailable($user->email, new CostPlusOverpriceMail($quote, $request, $organization, $over, $markup));
            $quote->payload = array_merge(is_array($quote->payload) ? $quote->payload : [], [
                'cost_plus_guard' => [
                    'notified_at' => now()->toIso8601String(),
                    'user_id' => $user->id,
                    'lines' => count($over),
                ],
            ]);
            $quote->save();
            Log::info('CostPlusPriceGuard: overprice notified', [
                'quote_id' => $quote->id, 'request_id' => $request->id, 'user_id' => $user->id, 'lines' => count($over),
            ]);
        } catch (\Throwable $e) {
            Log::warning('CostPlusPriceGuard: email failed (non-fatal)', ['quote_id' => $quote->id, 'error' => $e->getMessage()]);
        }

        return $over;
    }

    /** Покупатель документа: по ИНН из реквизитов, иначе контрагент заявки. */
    private function buyer(OutboundQuote $quote, Request $request): ?Organization
    {
        $inn = trim((string) (($quote->payload ?? [])['requisites_buyer_inn'] ?? ''));
        if ($inn !== '') {
            $byInn = Organization::query()->where('inn', $inn)->first();
            if ($byInn !== null) {
                return $byInn;
            }
        }

        return $request->organization_id ? Organization::query()->find($request->organization_id) : null;
    }

    /** Только свежие документы: переразбор старых не должен слать письма. */
    private function fresh(OutboundQuote $quote): bool
    {
        $sentAt = $quote->email_message_id
            ? EmailMessage::withHistory()->whereKey($quote->email_message_id)->value('sent_at')
            : null;
        $days = (int) config('services.pricing.cost_plus_guard_max_age_days', 3);

        return $sentAt !== null && now()->subDays($days)->lte($sentAt);
    }

    private function alreadyNotified(OutboundQuote $quote): bool
    {
        return ! empty(($quote->payload ?? [])['cost_plus_guard']['notified_at'] ?? null);
    }

    /** Кто отправил документ; если не сотрудник — менеджер заявки. */
    private function responsible(OutboundQuote $quote, Request $request): ?User
    {
        $sender = $quote->email_message_id
            ? mb_strtolower((string) EmailMessage::withHistory()->whereKey($quote->email_message_id)->value('from_email'))
            : '';
        $user = $sender !== '' ? User::query()->whereRaw('lower(email) = ?', [$sender])->first() : null;

        return $user ?? ($request->assigned_user_id ? User::query()->find($request->assigned_user_id) : null);
    }
}
