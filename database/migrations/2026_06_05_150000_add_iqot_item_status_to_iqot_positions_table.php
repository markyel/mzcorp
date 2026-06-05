<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Прогресс сбора по позиции (со стороны IQOT) до готового отчёта:
 * iqot_item_status = items[].status из ответа GET /submissions/{id}
 * (dispatched / awaiting_suppliers / with_offers / completed). Для индикации
 * движения, пока офферы собираются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iqot_positions', function (Blueprint $t) {
            if (! Schema::hasColumn('iqot_positions', 'iqot_item_status')) {
                $t->string('iqot_item_status', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('iqot_positions', function (Blueprint $t) {
            if (Schema::hasColumn('iqot_positions', 'iqot_item_status')) {
                $t->dropColumn('iqot_item_status');
            }
        });
    }
};
