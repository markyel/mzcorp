<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кеш сигналов сравнения цен для SQL-фильтра «требуют внимания» в разделе IQOT.
 * priceComparison() считается в PHP по JSON-отчёту — фильтровать по нему в
 * пагинированном списке нельзя. Кешируем ранг нашей цены, отклонение от лучшей
 * цены IQOT (без НДС, %) и общее число строк сравнения. Пересчитываются при
 * раскладке отчёта (PollIqotSubmissionsJob) и при обновлении курсов
 * (iqot:update-fx-rates). Пороги внимания применяются к этим колонкам в
 * запросе — остаются «живыми» (правка в Настройках влияет сразу).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iqot_positions', function (Blueprint $t) {
            if (! Schema::hasColumn('iqot_positions', 'cmp_our_rank')) {
                $t->unsignedSmallInteger('cmp_our_rank')->nullable();
            }
            if (! Schema::hasColumn('iqot_positions', 'cmp_deviation_pct')) {
                $t->decimal('cmp_deviation_pct', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('iqot_positions', 'cmp_total')) {
                $t->unsignedSmallInteger('cmp_total')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('iqot_positions', function (Blueprint $t) {
            foreach (['cmp_our_rank', 'cmp_deviation_pct', 'cmp_total'] as $col) {
                if (Schema::hasColumn('iqot_positions', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
