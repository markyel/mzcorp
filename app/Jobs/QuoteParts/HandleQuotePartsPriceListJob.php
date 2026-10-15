<?php

namespace App\Jobs\QuoteParts;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\QuotePartsPriceListNotification;
use App\Services\QuoteParts\QuotePartsPriceService;
use App\Services\QuoteParts\QuotePartsRfqService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Поставщик прислал прайс через QuoteParts, и он сопоставлен: копия офферов для «Снабжения»
 * и «колокольчик» снабжению — сколько цен записано и сколько строк ждут проверки.
 */
class HandleQuotePartsPriceListJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(QuotePartsPriceService $prices, QuotePartsRfqService $rfqs): void
    {
        $prices->syncOffers();

        $key = (string) ($this->payload['supplier'] ?? '');
        $supplier = $key !== '' ? ($rfqs->supplierNames([(object) ['snapshot' => ['suppliers' => [['external_id' => $key]]]]])[$key] ?? $key) : null;

        foreach (User::role(Role::Procurement->value)->whereNull('archived_at')->get() as $user) {
            $user->notify(new QuotePartsPriceListNotification((int) $this->payload['price_list_id'], $supplier, (array) ($this->payload['stats'] ?? [])));
        }
    }
}
