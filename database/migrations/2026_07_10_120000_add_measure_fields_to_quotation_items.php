<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Двумерные позиции в КП: снапшот второй размерности qty × length.
 *
 * До этой миграции QuotationItem хранил только `qty` + `unit`, поэтому при
 * копировании мерной позиции заявки (напр. «6 шт × 55 м» — кабель/ремень)
 * вторая размерность (55 м) терялась: сумма считалась как price × qty без
 * множителя длины (занижение для товаров с ценой за метр).
 *
 * Теперь метраж снапшотится в сам QuotationItem (по snapshot-принципу КП,
 * как и цена/название), а `qty` остаётся в штуках:
 *   piece_length      — длина одного куска (55.000), null для одномерных;
 *   piece_length_unit — единица второй размерности («м», «п.м.», «кг», «л»);
 *   bill_by_length    — как менеджер выставил единицу цены в карточке позиции:
 *                         false → цена за штуку, line_total = price × qty;
 *                         true  → цена за метр,  line_total = price × qty × piece_length.
 *
 * Источник значения bill_by_length — RequestItem::effectiveUnit() == parsed_length_unit
 * (billing_unit, выбранный менеджером). Отображение в КП: «6 шт × 55 м», цена «₽/м».
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            if (! Schema::hasColumn('quotation_items', 'piece_length')) {
                $table->decimal('piece_length', 12, 3)->nullable()->after('unit');
            }
            if (! Schema::hasColumn('quotation_items', 'piece_length_unit')) {
                $table->string('piece_length_unit', 16)->nullable()->after('piece_length');
            }
            if (! Schema::hasColumn('quotation_items', 'bill_by_length')) {
                $table->boolean('bill_by_length')->default(false)->after('piece_length_unit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            foreach (['bill_by_length', 'piece_length_unit', 'piece_length'] as $col) {
                if (Schema::hasColumn('quotation_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
