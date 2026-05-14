<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1.11 — Attention-механизм (Foundation §5.3 + §5.5).
 *
 * У каждой не-терминальной не-paused заявки есть дедлайн, к которому она
 * должна снова попасть в фокус менеджера. Если NOW > attention_required_at —
 * `attention_level = 1` (overdue), денормализованный флаг ставит cron
 * `requests:check-attention` чтобы Pool мог сортировать просроченные сверху
 * без `whereRaw('NOW() > attention_required_at')`.
 *
 *  - attention_required_at — timestamp дедлайна; NULL значит «ничего не ждём»
 *    (terminal / paused / Paid в ожидании моментального closed_won).
 *  - attention_reason — enum App\Enums\AttentionReason; идёт парой с _at.
 *  - attention_level — 0 = normal, 1 = overdue. Меняется только cron'ом.
 *
 * Composite index (assigned_user_id, attention_required_at) — основной для
 * Pool ORDER BY attention_level DESC, attention_required_at ASC NULLS LAST.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (! Schema::hasColumn('requests', 'attention_required_at')) {
                $table->timestamp('attention_required_at')->nullable()->after('closed_lost_comment');
            }
            if (! Schema::hasColumn('requests', 'attention_reason')) {
                $table->string('attention_reason', 40)->nullable()->after('attention_required_at');
            }
            if (! Schema::hasColumn('requests', 'attention_level')) {
                $table->smallInteger('attention_level')->default(0)->after('attention_reason');
            }
        });

        $hasMainIdx = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'requests' AND indexname = 'requests_assigned_attention_idx'"
        ))->isNotEmpty();
        if (! $hasMainIdx) {
            DB::statement(
                'CREATE INDEX requests_assigned_attention_idx '
                . 'ON requests (assigned_user_id, attention_level DESC, attention_required_at NULLS LAST)'
            );
        }

        // Лёгкий индекс для дашбордного COUNT просроченных по всем менеджерам.
        $hasOverdueIdx = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'requests' AND indexname = 'requests_attention_overdue_idx'"
        ))->isNotEmpty();
        if (! $hasOverdueIdx) {
            DB::statement(
                'CREATE INDEX requests_attention_overdue_idx '
                . 'ON requests (attention_level, attention_required_at) '
                . 'WHERE attention_level = 1'
            );
        }
    }

    public function down(): void
    {
        $indexes = ['requests_attention_overdue_idx', 'requests_assigned_attention_idx'];
        foreach ($indexes as $idx) {
            $has = collect(DB::select(
                "SELECT indexname FROM pg_indexes WHERE tablename = 'requests' AND indexname = ?",
                [$idx]
            ))->isNotEmpty();
            if ($has) {
                DB::statement("DROP INDEX {$idx}");
            }
        }

        Schema::table('requests', function (Blueprint $table) {
            foreach (['attention_level', 'attention_reason', 'attention_required_at'] as $col) {
                if (Schema::hasColumn('requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
