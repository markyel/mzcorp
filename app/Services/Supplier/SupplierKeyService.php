<?php

namespace App\Services\Supplier;

use App\Models\SupplierOrganization;
use App\Services\QuoteParts\QuotePartsClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Смена ключа поставщика (временный «mz:{id}» → код контрагента 1С): в QuoteParts, у организации
 * и во всех наших таблицах, где ключ хранится строкой (офферы, отказы, выбор поставщика по
 * позиции, коды 1С, снимки запросов QuoteParts).
 */
class SupplierKeyService
{
    public const KEYED_TABLES = ['quoteparts_offers', 'quoteparts_item_declines', 'catalog_item_supplier_choices', 'supplier_onec_codes'];

    public function __construct(private readonly QuotePartsClient $client) {}

    public function setOnecCode(SupplierOrganization $org, string $code, ?string $onecName = null): SupplierOrganization
    {
        $code = trim($code);
        if ($code === '') {
            throw new RuntimeException('Укажите код 1С.');
        }
        $taken = SupplierOrganization::query()->whereKeyNot($org->id)
            ->where(fn ($q) => $q->where('onec_code', $code)->orWhere('qp_key', $code))->value('name');
        if ($taken !== null) {
            throw new RuntimeException("Код {$code} уже у поставщика «{$taken}» — это один поставщик, объедините их.");
        }

        $old = (string) $org->qp_key;
        if ($old !== '' && $old !== $code && $org->qp_status !== null) {
            // Сначала QuoteParts: не принял — у нас ничего не меняем.
            $this->client->updateSupplier($old, ['external_id' => $code]);
        }

        DB::transaction(function () use ($org, $old, $code, $onecName) {
            $candidate = collect((array) $org->onec_candidates)->firstWhere('code', $code);
            $org->forceFill([
                'onec_code' => $code,
                'onec_name' => $onecName ?? ($candidate['name'] ?? $org->onec_name),
                'onec_candidates' => null,
                'qp_key' => $code,
            ])->save();
            if ($old !== '' && $old !== $code) {
                $this->remap($old, $code);
            }
        });

        return $org->refresh();
    }

    /** Ссылки на прежний ключ — на новый (таблицы со строковым ключом и снимки запросов). */
    public function remap(string $old, string $new): int
    {
        $n = 0;
        foreach (self::KEYED_TABLES as $table) {
            if ($table === 'supplier_onec_codes' && DB::table($table)->where('supplier_key', $new)->exists()) {
                continue;
            }
            $n += DB::table($table)->where('supplier_key', $old)->update(['supplier_key' => $new]);
        }

        return $n + DB::update(
            'update quoteparts_rfqs set snapshot = replace(snapshot::text, ?, ?)::jsonb where snapshot::text like ?',
            ['"external_id": "'.$old.'"', '"external_id": "'.$new.'"', '%"external_id": "'.$old.'"%'],
        );
    }
}
