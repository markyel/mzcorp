<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Менеджер остановил досылку полного КП.
 *
 * Клиент мог передумать, отказаться или уйти к другому поставщику, не дожидаясь
 * второй половины предложения — тогда автоматическая досылка не нужна и даже
 * вредна. Отметка отдельная, а не стирание `partial_quote_started_at`: видно,
 * что заявка была частичной и что досылку прекратили осознанно.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('requests', 'partial_quote_stopped_at')) {
            return;
        }

        Schema::table('requests', function (Blueprint $table) {
            $table->timestamp('partial_quote_stopped_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('requests', 'partial_quote_stopped_at')) {
            return;
        }

        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn('partial_quote_stopped_at');
        });
    }
};
