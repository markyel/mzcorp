<?php

namespace App\Services\Catalog;

use App\Models\SupplyGroup;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Группы товаров Китай / Россия / Европа по «Закупкам 1С».
 *
 * 1. Регион каждого поставщика 1С: по названию (страна в названии, кириллица в имени до
 *    скобок — российская фирма), иначе по валюте закупок (USD/CNY — Китай, EUR/GBP/CHF —
 *    Европа, RUB — Россия). Снабжение может поправить — ручной регион пересчёт не трогает.
 * 2. Группы позиции — по регионам всех её поставщиков: закупали в Китае — «Китай», у
 *    российских — «Россия», «Европа» — только то, что импортируем из Европы и не из Китая.
 *    Позиция может быть в двух группах. Заданные руками группы пересчёт не трогает.
 */
class SupplyGroupService
{
    /** Базовые группы-регионы, которые узнаются по названию и валюте поставщика 1С. */
    public const CHINA = 'cn';

    public const RUSSIA = 'ru';

    public const EUROPE = 'eu';

    private const CN_WORDS = ['китай', 'china', 'chinese', 'ningbo', 'shanghai', 'suzhou', 'guangzhou', 'shenzhen', 'zhejiang', 'jiangsu'];

    private const EU_WORDS = [
        'italy', 'italia', 'germany', 'deutschland', 'gmbh', 's.p.a', 'spa ', 'srl', 's.r.l', 's.l.', 's.a.', 'spain', 'españa',
        'turkey', 'türkiye', 'greece', 'poland', 'polska', 'france', 'sarl', 'b.v.', 'austria', 'czech', 'slovenia', 'europe',
        'unisystem', 'paulschaab', 'германия', 'италия', 'испания', 'турци', 'греция', 'польша',
    ];

    private const CURRENCY_REGION = ['USD' => 'cn', 'CNY' => 'cn', 'EUR' => 'eu', 'GBP' => 'eu', 'CHF' => 'eu', 'RUB' => 'ru'];

    /**
     * @param  array<string, int>  $currencies  валюта => сколько позиций
     */
    public static function classify(string $name, array $currencies): ?string
    {
        $lower = mb_strtolower($name);
        foreach (self::CN_WORDS as $w) {
            if (str_contains($lower, $w)) {
                return self::CHINA;
            }
        }
        if (str_contains($lower, 'россия') || str_contains($lower, 'russia')) {
            return self::RUSSIA;
        }
        // Имя до пояснений в скобках: кириллицей — российская фирма (даже с закупками в EUR).
        $head = trim((string) preg_split('/[(\[]/u', $name)[0]);
        $cyr = preg_match_all('/\p{Cyrillic}/u', $head);
        $lat = preg_match_all('/[A-Za-z]/', $head);
        if ($cyr > $lat) {
            return self::RUSSIA;
        }
        foreach (self::EU_WORDS as $w) {
            if (str_contains($lower, $w)) {
                return self::EUROPE;
            }
        }
        arsort($currencies);
        foreach (array_keys($currencies) as $currency) {
            if (isset(self::CURRENCY_REGION[strtoupper((string) $currency)])) {
                return self::CURRENCY_REGION[strtoupper((string) $currency)];
            }
        }

        return null;
    }

    /**
     * Поставщики из «Закупок 1С» → регионы: новые добавляются, у автоматических регион
     * пересчитывается, ручные остаются как есть.
     *
     * @return int сколько поставщиков
     */
    public function refreshRegions(): int
    {
        $rows = DB::table('catalog_supplier_prices')
            ->selectRaw('supplier_name_1c, currency, count(distinct catalog_item_id) as items')
            ->whereNotNull('supplier_name_1c')->groupBy('supplier_name_1c', 'currency')->get()
            ->groupBy('supplier_name_1c');
        $existing = DB::table('purchase_supplier_regions')->get()->keyBy('supplier_name_1c');

        foreach ($rows as $name => $byCurrency) {
            $currencies = $byCurrency->mapWithKeys(fn ($r) => [(string) ($r->currency ?? '') => (int) $r->items])->all();
            $old = $existing->get($name);
            $values = [
                'items_count' => (int) DB::table('catalog_supplier_prices')->where('supplier_name_1c', $name)->distinct()->count('catalog_item_id'),
                'currencies' => json_encode($currencies),
                'updated_at' => now(),
            ];
            if ($old === null) {
                DB::table('purchase_supplier_regions')->insert($values + ['supplier_name_1c' => $name, 'region' => self::known(self::classify($name, $currencies)), 'source' => 'auto', 'created_at' => now()]);
            } else {
                DB::table('purchase_supplier_regions')->where('id', $old->id)
                    ->update($values + ($old->source === 'manual' ? [] : ['region' => self::known(self::classify($name, $currencies))]));
            }
        }

        return $rows->count();
    }

    /** Код группы, только если такая группа есть в справочнике. */
    private static function known(?string $code): ?string
    {
        return $code !== null && in_array($code, SupplyGroup::codes(), true) ? $code : null;
    }

    public function setRegion(int $regionId, ?string $region, User $by): void
    {
        DB::table('purchase_supplier_regions')->where('id', $regionId)->update([
            'region' => in_array($region, SupplyGroup::codes(), true) ? $region : null,
            'source' => 'manual',
            'updated_by_user_id' => $by->id,
            'updated_at' => now(),
        ]);
    }

    /**
     * «Привязать к карточке»: поставщик 1С — это карточка справочника. Закупки с этим названием
     * получают карточку сразу; следующие загрузки «Закупок 1С» берут привязку раньше названия.
     *
     * @return int сколько закупок привязано
     */
    public function linkSupplier(int $regionId, int $supplierId, User $by): int
    {
        $row = DB::table('purchase_supplier_regions')->where('id', $regionId)->first() ?? abort(404);
        abort_unless(DB::table('suppliers')->where('id', $supplierId)->exists(), 404);

        return DB::transaction(function () use ($row, $supplierId, $by) {
            DB::table('purchase_supplier_regions')->where('id', $row->id)
                ->update(['supplier_id' => $supplierId, 'updated_by_user_id' => $by->id, 'updated_at' => now()]);

            return DB::table('catalog_supplier_prices')->where('supplier_name_1c', $row->supplier_name_1c)
                ->update(['supplier_id' => $supplierId, 'updated_at' => now()]);
        });
    }

    /** Привязки «поставщик 1С → карточка», сделанные руками. @return array<string, int> */
    public static function manualLinks(): array
    {
        return DB::table('purchase_supplier_regions')->whereNotNull('supplier_id')->pluck('supplier_id', 'supplier_name_1c')
            ->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Пересчитать группы позиций по регионам поставщиков (кроме заданных руками).
     *
     * @return array<string, int> группа => сколько позиций
     */
    public function rebuild(): array
    {
        $regions = [];
        DB::table('catalog_supplier_prices as p')
            ->join('purchase_supplier_regions as r', 'r.supplier_name_1c', '=', 'p.supplier_name_1c')
            ->whereNotNull('p.catalog_item_id')->whereNotNull('r.region')
            ->select('p.catalog_item_id', 'r.region')->distinct()->orderBy('p.catalog_item_id')
            ->cursor()->each(function ($row) use (&$regions) {
                $regions[(int) $row->catalog_item_id][$row->region] = true;
            });

        $manual = DB::table('catalog_item_supply_groups')->where('source', 'manual')->distinct()->pluck('catalog_item_id')->flip();
        $rows = [];
        foreach ($regions as $itemId => $set) {
            if ($manual->has($itemId)) {
                continue;
            }
            foreach (self::groupsOf(array_keys($set)) as $code) {
                $rows[] = ['catalog_item_id' => $itemId, 'group_code' => $code, 'source' => 'auto', 'created_at' => now(), 'updated_at' => now()];
            }
        }

        DB::transaction(function () use ($rows) {
            DB::table('catalog_item_supply_groups')->where('source', 'auto')->delete();
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('catalog_item_supply_groups')->insert($chunk);
            }
        });

        return $this->counts();
    }

    /**
     * Регионы поставщиков позиции → её группы: группа есть, если позицию закупали у поставщика
     * её региона, — кроме групп с условием «не если позиция в группе …» (Европа — не из Китая).
     *
     * @param  list<string>  $regions
     * @param  array<string, ?string>|null  $rules  код группы => exclude_if_code, по порядку; null — из справочника
     * @return list<string>
     */
    public static function groupsOf(array $regions, ?array $rules = null): array
    {
        $rules ??= SupplyGroup::ordered()->pluck('exclude_if_code', 'code')->all();
        $groups = [];
        foreach ($rules as $code => $excludeIf) {
            if (in_array($code, $regions, true) && ($excludeIf === null || ! in_array($excludeIf, $regions, true))) {
                $groups[] = (string) $code;
            }
        }

        return $groups;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return DB::table('catalog_item_supply_groups')->selectRaw('group_code, count(*) as c')->groupBy('group_code')->pluck('c', 'group_code')
            ->map(fn ($c) => (int) $c)->all();
    }

    /**
     * @param  list<int>  $catalogItemIds
     * @return array<int, list<string>>
     */
    public function groupsFor(array $catalogItemIds): array
    {
        $out = [];
        foreach (DB::table('catalog_item_supply_groups')->whereIn('catalog_item_id', $catalogItemIds)->orderBy('group_code')->get(['catalog_item_id', 'group_code']) as $r) {
            $out[(int) $r->catalog_item_id][] = $r->group_code;
        }

        return $out;
    }

    /**
     * Группы позиции руками; пустой список — снова автоматически.
     *
     * @param  list<string>  $codes
     */
    public function setItemGroups(int $catalogItemId, array $codes): void
    {
        $codes = array_values(array_intersect(SupplyGroup::codes(), $codes));
        DB::transaction(function () use ($catalogItemId, $codes) {
            DB::table('catalog_item_supply_groups')->where('catalog_item_id', $catalogItemId)->delete();
            foreach ($codes as $code) {
                DB::table('catalog_item_supply_groups')->insert(['catalog_item_id' => $catalogItemId, 'group_code' => $code, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        if ($codes === []) {
            $this->rebuildOne($catalogItemId);
        }
    }

    private function rebuildOne(int $catalogItemId): void
    {
        $regions = DB::table('catalog_supplier_prices as p')
            ->join('purchase_supplier_regions as r', 'r.supplier_name_1c', '=', 'p.supplier_name_1c')
            ->where('p.catalog_item_id', $catalogItemId)->whereNotNull('r.region')->distinct()->pluck('r.region')->all();
        foreach (self::groupsOf($regions) as $code) {
            DB::table('catalog_item_supply_groups')->insert(['catalog_item_id' => $catalogItemId, 'group_code' => $code, 'source' => 'auto', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Регион поставщика из справочника — по его закупкам в 1С (сам адрес или вся его
     * организация); для групп, которые он видит в QuoteParts.
     */
    public function regionOf(Supplier $supplier): ?string
    {
        $ids = $supplier->supplier_organization_id
            ? Supplier::query()->where('supplier_organization_id', $supplier->supplier_organization_id)->pluck('id')->all()
            : [$supplier->id];

        return DB::table('catalog_supplier_prices as p')
            ->join('purchase_supplier_regions as r', 'r.supplier_name_1c', '=', 'p.supplier_name_1c')
            ->whereIn('p.supplier_id', $ids)->whereNotNull('r.region')
            ->selectRaw('r.region, count(*) as c')->groupBy('r.region')->orderByDesc('c')->value('r.region');
    }
}
