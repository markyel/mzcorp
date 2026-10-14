<?php

namespace App\Services\QuoteParts;

use App\Models\Supplier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Приглашение поставщиков из справочника mzcorp в QuoteParts и статус приглашения.
 * Поставщик получает письмо от имени Моего Зипа (EN + ZH), по ссылке принимает
 * предложенный режим цены или предлагает свой.
 */
class QuotePartsSupplierService
{
    public const STATUS_LABELS = [
        'invited' => 'Приглашён',
        'terms_discussed' => 'Предлагает другие условия',
        'active' => 'Работаем',
        'declined' => 'Отказался',
    ];

    public function __construct(private readonly QuotePartsClient $client) {}

    /**
     * @param  array{company_name:string, contact_name:?string, email:string, locale:string, country:?string, incoterm:?string, incoterm_place:?string, currency:?string, payment_terms:?string, packaging_included:?bool, offer_validity_days:?int, message:?string}  $form
     */
    public function invite(Supplier $supplier, array $form): Supplier
    {
        $result = $this->client->inviteSupplier([
            'external_id' => (string) $supplier->id,
            'company_name' => $form['company_name'],
            'country' => $form['country'] ?: null,
            'contact' => [
                'email' => $form['email'],
                'name' => $form['contact_name'] ?: null,
                'locale' => $form['locale'],
            ],
            'terms' => array_filter([
                'incoterm' => $form['incoterm'] ?: null,
                'incoterm_place' => $form['incoterm_place'] ?: null,
                'currency' => $form['currency'] ?: null,
                'payment_terms' => $form['payment_terms'] ?: null,
                'packaging_included' => $form['packaging_included'],
                'offer_validity_days' => $form['offer_validity_days'] ?: null,
            ], fn ($v) => $v !== null),
            'message' => $form['message'] ?: null,
            'show_internal_code' => (bool) ($form['show_internal_code'] ?? false),
        ]);

        $supplier->forceFill([
            'quoteparts_link_id' => $result['id'],
            'quoteparts_status' => $result['status'],
            'quoteparts_invited_at' => now(),
            'quoteparts_comment' => $result['supplier_comment'] ?? null,
        ])->save();

        return $supplier;
    }

    /**
     * Подтянуть статусы приглашённых поставщиков (принял, предложил другие условия, отказался).
     */
    public function refreshStatuses(): int
    {
        $updated = 0;

        Supplier::query()->whereNotNull('quoteparts_link_id')->select(['id', 'quoteparts_status', 'quoteparts_comment'])
            ->chunkById(500, function ($suppliers) use (&$updated) {
                try {
                    $links = collect($this->client->supplierLinks($suppliers->pluck('id')->map(fn ($id) => (string) $id)->all()))
                        ->keyBy('external_id');
                } catch (Throwable $e) {
                    Log::warning('QuoteParts supplier status refresh failed', ['error' => $e->getMessage()]);

                    return false;
                }

                foreach ($suppliers as $supplier) {
                    $link = $links->get((string) $supplier->id);
                    if ($link === null) {
                        continue;
                    }
                    if ($link['status'] !== $supplier->quoteparts_status || ($link['supplier_comment'] ?? null) !== $supplier->quoteparts_comment) {
                        Supplier::whereKey($supplier->id)->update([
                            'quoteparts_status' => $link['status'],
                            'quoteparts_comment' => $link['supplier_comment'] ?? null,
                        ]);
                        $updated++;
                    }
                }
            });

        return $updated;
    }
}
