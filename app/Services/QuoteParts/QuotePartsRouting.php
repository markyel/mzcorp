<?php

namespace App\Services\QuoteParts;

use App\Models\Supplier;

/**
 * Поставщики, принявшие приглашение QuoteParts, получают запросы цен через платформу —
 * из заявки, из «Снабжения» и от мониторинга цен, у любого сотрудника; остальным — письмо,
 * как раньше. Поставщик в QuoteParts — организация: принял один адрес — через платформу
 * идут запросы всем адресам организации. Письмо — на отмеченный адрес; автоматический
 * перезапрос — на ящик по режиму поставщика в QuoteParts (главный / по очереди).
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
     * Два пути: принявшие приглашение — через платформу (её письмо с myzip@quoteparts.net и форма);
     * все остальные — внесистемные, через платформу нашим письмом с ящика orders@. Письмом из
     * mzCorp (с ящика менеджера) — только когда маршрутизация выключена целиком (переключатель
     * «Пилот») или QuoteParts не настроен.
     *
     * Раньше адрес без отметки quoteparts_offsystem шёл с ящика менеджера: так ушёл запрос на
     * spareparts@paulschaab.de (MZ2026-00339, 10.11.2026) — адрес завела синхронизация без отметки.
     *
     * @param  list<int>  $supplierIds
     * @return array{quoteparts: list<int>, offsystem: list<int>, mail: list<int>}
     */
    public function split(array $supplierIds): array
    {
        $supplierIds = array_values(array_unique(array_map('intval', $supplierIds)));
        if ($supplierIds === [] || ! self::enabled()) {
            return ['quoteparts' => [], 'offsystem' => [], 'mail' => $supplierIds];
        }

        $route = $this->classify($supplierIds);

        return ['quoteparts' => $route['quoteparts'], 'offsystem' => [...$route['offsystem'], ...$route['mail']], 'mail' => []];
    }

    /**
     * То же разделение без выключателя маршрутизации — для блока «Запрос через QuoteParts» заявки:
     * он шлёт только через платформу, но внесистемным — нашим письмом с orders@, как и обычная форма.
     * «mail» здесь — заведённые в QuoteParts без отметки «внесистемный»: им письмо платформы.
     *
     * @param  list<int>  $supplierIds
     * @return array{quoteparts: list<int>, offsystem: list<int>, mail: list<int>}
     */
    public function classify(array $supplierIds): array
    {
        $supplierIds = array_values(array_unique(array_map('intval', $supplierIds)));
        if ($supplierIds === []) {
            return ['quoteparts' => [], 'offsystem' => [], 'mail' => []];
        }

        $rows = Supplier::query()->whereIn('id', $supplierIds)->get(['id', 'supplier_organization_id', 'quoteparts_status', 'quoteparts_offsystem']);
        $activeOrgs = Supplier::query()->where('quoteparts_status', 'active')->whereNotNull('supplier_organization_id')
            ->whereIn('supplier_organization_id', $rows->pluck('supplier_organization_id')->filter())
            ->pluck('supplier_organization_id')->unique()->all();

        $viaQp = $rows->filter(fn (Supplier $s) => $s->quoteparts_status === 'active'
            || ($s->supplier_organization_id !== null && in_array($s->supplier_organization_id, $activeOrgs, true)))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $offsystem = $rows->filter(fn (Supplier $s) => $s->quoteparts_offsystem && ! in_array((int) $s->id, $viaQp, true))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'quoteparts' => array_values($viaQp),
            'offsystem' => array_values($offsystem),
            'mail' => array_values(array_diff($supplierIds, $viaQp, $offsystem)),
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

    /**
     * Внесистемные поставщики (id), запрос которым уйдёт нашим письмом через QuoteParts (orders@), — для пометки.
     *
     * @param  iterable<int>  $supplierIds
     * @return array<int, true>
     */
    public function offsystemIds(iterable $supplierIds): array
    {
        return array_fill_keys($this->split(collect($supplierIds)->map(fn ($id) => (int) $id)->all())['offsystem'], true);
    }
}
