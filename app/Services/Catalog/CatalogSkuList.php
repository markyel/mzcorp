<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Список M-артикулов, вставленный разом (столбец из Excel, через запятую и т.п.) → позиции каталога.
 * Кириллическая «М», пробел или дефис после буквы, пропущенные ведущие нули — частые ошибки, их
 * прощаем. Снятые позиции («ЗАМЕНЕНО НА») берём, только если активной с тем же артикулом нет.
 * Общее для «Снабжения» и раздела «QuoteParts» (заказчик, 13.10.2026).
 */
class CatalogSkuList
{
    /**
     * @return array{wanted: list<string>, found: Collection<string, CatalogItem>, missing: list<string>}
     */
    public static function resolve(string $text): array
    {
        preg_match_all('/(?<![\p{L}\d])[MМmм]\s?-?\s?(\d{1,5})(?!\d)/u', $text, $m);
        $wanted = [];
        foreach ($m[1] as $digits) {
            // В каталоге все артикулы — M + 5 цифр; «M5333» = M05333.
            $wanted['M'.str_pad($digits, 5, '0', STR_PAD_LEFT)] = true;
        }
        $wanted = array_keys($wanted);
        if ($wanted === []) {
            return ['wanted' => [], 'found' => collect(), 'missing' => []];
        }

        $found = CatalogItem::query()
            ->whereIn(DB::raw('upper(sku)'), $wanted)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get(['id', 'sku'])
            ->unique(fn (CatalogItem $ci) => mb_strtoupper((string) $ci->sku))
            ->keyBy(fn (CatalogItem $ci) => mb_strtoupper((string) $ci->sku));

        return [
            'wanted' => $wanted,
            'found' => $found,
            'missing' => array_values(array_filter($wanted, fn ($sku) => ! $found->has($sku))),
        ];
    }
}
