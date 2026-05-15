<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Слияние заявок (RequestMergeService).
 *
 * Когда два дубликата от одного клиента сливают в одну, loser-заявка
 * закрывается (status=closed_lost, reason=duplicate) и получает ссылку
 * на winner'а через `merged_into_id`. UI Detail показывает chip «↳ слита
 * в M-NNNN» вместо обычного closed_lost блока.
 *
 *  - merged_into_id — FK без constraint (не падать при soft-delete winner'а
 *    в будущем). Lookup через Request::find().
 *  - merged_at — timestamp слияния, для audit и сортировки в Pool.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (! Schema::hasColumn('requests', 'merged_into_id')) {
                $table->unsignedBigInteger('merged_into_id')->nullable()->after('closed_lost_comment');
            }
            if (! Schema::hasColumn('requests', 'merged_at')) {
                $table->timestamp('merged_at')->nullable()->after('merged_into_id');
            }
        });

        // Индекс для обратного lookup (find all merged-into me) — для UI
        // «эта заявка получила слияния из M-A, M-B, ...».
        if (! collect(\Illuminate\Support\Facades\DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'requests' AND indexname = 'requests_merged_into_idx'"
        ))->count()) {
            \Illuminate\Support\Facades\DB::statement(
                'CREATE INDEX requests_merged_into_idx ON requests (merged_into_id) WHERE merged_into_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        if (collect(\Illuminate\Support\Facades\DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'requests' AND indexname = 'requests_merged_into_idx'"
        ))->count()) {
            \Illuminate\Support\Facades\DB::statement('DROP INDEX requests_merged_into_idx');
        }

        Schema::table('requests', function (Blueprint $table) {
            foreach (['merged_at', 'merged_into_id'] as $col) {
                if (Schema::hasColumn('requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
