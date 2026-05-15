<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending position suggestions от reply-парсинга.
 *
 * Когда клиент в reply прислал «вот фото забытой позиции», парсер
 * запускается (через ReplyParseGate) и может извлечь позиции. Если
 * confidence < auto-apply threshold (например, Vision не очень уверен
 * или артикул близок к существующему) — позиция персистится как
 * `suggestion_status='pending'` и НЕ показывается в активном списке.
 * Менеджер apply'ит или reject'ит через UI плашку.
 *
 * Значения: NULL = обычная активная позиция, не было через suggestion;
 *           pending  — Vision добавил, менеджер ещё не решил;
 *           applied  — менеджер подтвердил → активна, is_active=true;
 *           rejected — менеджер отклонил → soft-delete, is_active=false.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            if (! Schema::hasColumn('request_items', 'suggestion_status')) {
                $table->string('suggestion_status', 16)->nullable()->after('quality_assessment_payload');
            }
            if (! Schema::hasColumn('request_items', 'suggestion_confidence')) {
                $table->decimal('suggestion_confidence', 4, 3)->nullable()->after('suggestion_status');
            }
            if (! Schema::hasColumn('request_items', 'suggestion_source_email_id')) {
                $table->unsignedBigInteger('suggestion_source_email_id')->nullable()->after('suggestion_confidence');
            }
        });

        // Partial index — для UI «есть pending по этой заявке?» дешёвая проверка.
        if (! collect(\Illuminate\Support\Facades\DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'request_items' AND indexname = 'request_items_pending_suggestions_idx'"
        ))->count()) {
            \Illuminate\Support\Facades\DB::statement(
                "CREATE INDEX request_items_pending_suggestions_idx
                 ON request_items (request_id)
                 WHERE suggestion_status = 'pending'"
            );
        }
    }

    public function down(): void
    {
        if (collect(\Illuminate\Support\Facades\DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'request_items' AND indexname = 'request_items_pending_suggestions_idx'"
        ))->count()) {
            \Illuminate\Support\Facades\DB::statement('DROP INDEX request_items_pending_suggestions_idx');
        }

        Schema::table('request_items', function (Blueprint $table) {
            foreach (['suggestion_source_email_id', 'suggestion_confidence', 'suggestion_status'] as $col) {
                if (Schema::hasColumn('request_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
