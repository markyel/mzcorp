<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pool re-sort + manual attention flag.
 *
 *  - requests.last_activity_at — denormalized timestamp последней активности
 *    по заявке (любое state_change / inbound-link / outbound-link / manual
 *    edit / assignment). Используется для Pool ORDER BY как «свежие сверху»
 *    при прочих равных. Touch'ится через RequestActivityService::touch().
 *
 *  - requests.attention_manual_by_user_id — кто поставил ручной флаг
 *    attention (AttentionReason::Manual). NULL = флаг не вручную, обычный
 *    расчёт SLA. Не FK, чтобы не падать при soft-delete user'а.
 *
 * Composite index (assigned_user_id, attention_level DESC, last_activity_at
 * DESC NULLS LAST) — основной для Pool. Старый composite по
 * attention_required_at оставляем — он всё ещё нужен для legacy запросов.
 *
 * Backfill last_activity_at = GREATEST(updated_at, created_at) — простой,
 * без пробежки по state_changes / email_messages. На следующий transition
 * / inbound / outbound значение естественным образом обновится точнее.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (! Schema::hasColumn('requests', 'last_activity_at')) {
                $table->timestamp('last_activity_at')->nullable()->after('attention_level');
            }
            if (! Schema::hasColumn('requests', 'attention_manual_by_user_id')) {
                $table->unsignedBigInteger('attention_manual_by_user_id')->nullable()->after('attention_reason');
            }
        });

        // Backfill last_activity_at для существующих строк.
        DB::statement(
            'UPDATE requests SET last_activity_at = GREATEST(updated_at, created_at) '
            . 'WHERE last_activity_at IS NULL'
        );

        // Pool sort index: assigned_user + attention_level DESC + last_activity_at DESC.
        $hasIdx = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'requests' AND indexname = 'requests_pool_activity_idx'"
        ))->isNotEmpty();
        if (! $hasIdx) {
            DB::statement(
                'CREATE INDEX requests_pool_activity_idx '
                . 'ON requests (assigned_user_id, attention_level DESC, last_activity_at DESC NULLS LAST)'
            );
        }
    }

    public function down(): void
    {
        $hasIdx = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'requests' AND indexname = 'requests_pool_activity_idx'"
        ))->isNotEmpty();
        if ($hasIdx) {
            DB::statement('DROP INDEX requests_pool_activity_idx');
        }

        Schema::table('requests', function (Blueprint $table) {
            foreach (['last_activity_at', 'attention_manual_by_user_id'] as $col) {
                if (Schema::hasColumn('requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
