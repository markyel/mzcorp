<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Частичное КП» (18.11.2026): позиция, не покрытая ни одним отправленным КП, держит заявку в «Частичном КП». Менеджер
 * может закрыть её сам: substitute — «закрыто заменой» (предложили другое), declined — «мы отказали» (не поставляем).
 * Такие позиции не держат статус и не переезжают в дочернюю заявку на остаток.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('request_items')) {
            return;
        }
        Schema::table('request_items', function (Blueprint $table) {
            if (! Schema::hasColumn('request_items', 'quote_resolution')) {
                $table->string('quote_resolution', 20)->nullable();
            }
            if (! Schema::hasColumn('request_items', 'quote_resolved_by_user_id')) {
                $table->foreignId('quote_resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('request_items', 'quote_resolved_at')) {
                $table->timestamp('quote_resolved_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('request_items')) {
            return;
        }
        Schema::table('request_items', function (Blueprint $table) {
            if (Schema::hasColumn('request_items', 'quote_resolved_by_user_id')) {
                $table->dropConstrainedForeignId('quote_resolved_by_user_id');
            }
            foreach (['quote_resolution', 'quote_resolved_at'] as $column) {
                if (Schema::hasColumn('request_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
