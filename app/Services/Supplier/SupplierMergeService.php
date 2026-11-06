<?php

namespace App\Services\Supplier;

use App\Models\Supplier;
use App\Models\SupplierOrganization;
use App\Services\QuoteParts\QuotePartsClient;
use App\Services\QuoteParts\QuotePartsSupplierService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Объединить двух поставщиков (организации) в одного, не теряя ключ QuoteParts.
 *
 * Адреса второго переезжают к первому, ссылки на его ключ (офферы, отказы, выбор по позиции,
 * снимки запросов) — на ключ первого, контакты первого в QuoteParts обновляются. Связь второго в
 * QuoteParts остаётся (её история — его), но под ключом «merged:{ключ}», чтобы ключ освободился.
 * Два разных кода 1С не объединяются: это разные контрагенты, решать — в 1С.
 */
class SupplierMergeService
{
    public function __construct(
        private readonly QuotePartsClient $client,
        private readonly SupplierKeyService $keys,
    ) {}

    public function merge(SupplierOrganization $into, SupplierOrganization $from): SupplierOrganization
    {
        if ($into->id === $from->id) {
            throw new RuntimeException('Это один и тот же поставщик.');
        }
        if ($into->onec_code && $from->onec_code && $into->onec_code !== $from->onec_code) {
            throw new RuntimeException("У поставщиков разные коды 1С ({$into->onec_code} и {$from->onec_code}) — это разные контрагенты.");
        }

        $oldKey = (string) $from->qp_key;
        if ($oldKey !== '' && $from->qp_status !== null) {
            $this->client->updateSupplier($oldKey, ['external_id' => 'merged:'.$oldKey]);
        }

        DB::transaction(function () use ($into, $from, $oldKey) {
            // Код 1С переходит к оставшемуся, если у него не было.
            if (! $into->onec_code && $from->onec_code) {
                $code = $from->onec_code;
                $from->forceFill(['onec_code' => null, 'qp_key' => null])->save();
                $this->keys->setOnecCode($into, $code, $from->onec_name);
            }
            Supplier::query()->where('supplier_organization_id', $from->id)->update(['supplier_organization_id' => $into->id]);
            if ($oldKey !== '') {
                $this->keys->remap($oldKey, (string) $into->fresh()->qp_key);
            }
            $into->forceFill(['notes' => trim(implode("\n", array_filter([$into->notes, $from->notes]))) ?: null])->save();
            $from->delete();
        });

        $into->refresh();
        if ($into->qp_key) {
            app(QuotePartsSupplierService::class)->registerOrganization($into);
        }

        return $into->refresh();
    }
}
