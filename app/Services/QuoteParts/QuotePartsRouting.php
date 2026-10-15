<?php

namespace App\Services\QuoteParts;

use App\Models\Supplier;

/**
 * Поставщики, принявшие приглашение QuoteParts, получают запросы цен через платформу —
 * из заявки, из «Снабжения» и от мониторинга цен, у любого сотрудника; остальным — письмо,
 * как раньше. Поставщик в QuoteParts — организация: принял один адрес — через платформу
 * идут запросы всем адресам организации (QuoteParts сам решает, кому из людей писать).
 *
 * Выключатель (откат на старый путь): quoteparts.route_accepted во вкладке «Пилот».
 */
class QuotePartsRouting
{
    public const SETTING = 'quoteparts.route_accepted';

    public static function enabled(): bool
    {
        return (bool) app_setting(self::SETTING, true) && app(QuotePartsClient::class)->isConfigured();
    }

    /**
     * @param  list<int>  $supplierIds
     * @return array{quoteparts: list<int>, mail: list<int>}
     */
    public function split(array $supplierIds): array
    {
        $supplierIds = array_values(array_unique(array_map('intval', $supplierIds)));
        if ($supplierIds === [] || ! self::enabled()) {
            return ['quoteparts' => [], 'mail' => $supplierIds];
        }

        $rows = Supplier::query()->whereIn('id', $supplierIds)->get(['id', 'supplier_organization_id', 'quoteparts_status']);
        $activeOrgs = Supplier::query()->where('quoteparts_status', 'active')->whereNotNull('supplier_organization_id')
            ->whereIn('supplier_organization_id', $rows->pluck('supplier_organization_id')->filter())
            ->pluck('supplier_organization_id')->unique()->all();

        $viaQp = $rows->filter(fn (Supplier $s) => $s->quoteparts_status === 'active'
            || ($s->supplier_organization_id !== null && in_array($s->supplier_organization_id, $activeOrgs, true)))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'quoteparts' => array_values($viaQp),
            'mail' => array_values(array_diff($supplierIds, $viaQp)),
        ];
    }

    /**
     * Поставщики (id), запрос которым уйдёт через QuoteParts, — для пометки в списках выбора.
     *
     * @param  iterable<int>  $supplierIds
     * @return array<int, true>
     */
    public function routedIds(iterable $supplierIds): array
    {
        return array_fill_keys($this->split(collect($supplierIds)->map(fn ($id) => (int) $id)->all())['quoteparts'], true);
    }
}
