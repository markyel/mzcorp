<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Наша цена из последнего проигранного КП по позиции — для сравнения с офферами
 * IQOT (наглядное «наше КП vs конкуренты»). our_unit_price = QuotationItem
 * .final_unit_price (NET, без НДС). our_quotation_code = Quotation.internal_code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iqot_positions', function (Blueprint $t) {
            if (! Schema::hasColumn('iqot_positions', 'our_unit_price')) {
                $t->decimal('our_unit_price', 14, 2)->nullable();
            }
            if (! Schema::hasColumn('iqot_positions', 'our_quotation_code')) {
                $t->string('our_quotation_code', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('iqot_positions', function (Blueprint $t) {
            foreach (['our_unit_price', 'our_quotation_code'] as $col) {
                if (Schema::hasColumn('iqot_positions', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
