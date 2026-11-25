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
 * Разные коды 1С объединяются (25.11.2026): в 1С бывают дубли одного контрагента («Подий» —
 * 00-003020 «Поставщик» и 00-008624 «Покупатель»). Код первого остаётся основным, код второго —
 * дополнительный (onec_aliases): поиск по нему находит карточку, привязка реестра 1С не заводит дубль.
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
            // Код дубля (и его прежние дополнительные) — дополнительными кодами оставшегося.
            $aliases = self::aliasesAfterMerge($into, $from);
            $into->forceFill([
                'onec_aliases' => $aliases ?: null,
                'inn' => $into->inn ?: $from->inn,
            ])->save();
            $from->forceFill(['onec_code' => null, 'onec_aliases' => null, 'inn' => null])->save();
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

    /**
     * Дополнительные коды 1С оставшегося после склейки: его прежние + основной и дополнительные
     * вклеенного, без его собственного основного кода.
     *
     * @return list<string>
     */
    public static function aliasesAfterMerge(SupplierOrganization $into, SupplierOrganization $from): array
    {
        $codes = array_merge((array) $into->onec_aliases, [(string) $from->onec_code], (array) $from->onec_aliases);
        $codes = array_map(fn ($c) => trim((string) $c), $codes);

        return array_values(array_unique(array_filter($codes, fn ($c) => $c !== '' && $c !== (string) $into->onec_code)));
    }
}
