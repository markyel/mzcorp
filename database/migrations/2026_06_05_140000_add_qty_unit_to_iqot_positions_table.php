<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Количество и единица для запроса в IQOT — берутся из ПОСЛЕДНЕГО проигранного
 * КП по этой позиции (а не «1 шт»). Напр. канат: 576 м, а не 1 шт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iqot_positions', function (Blueprint $t) {
            if (! Schema::hasColumn('iqot_positions', 'qty')) {
                $t->decimal('qty', 14, 3)->nullable();
            }
            if (! Schema::hasColumn('iqot_positions', 'unit')) {
                $t->string('unit', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('iqot_positions', function (Blueprint $t) {
            foreach (['qty', 'unit'] as $col) {
                if (Schema::hasColumn('iqot_positions', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
