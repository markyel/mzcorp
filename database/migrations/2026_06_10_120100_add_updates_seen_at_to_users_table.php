<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Метка времени «когда пользователь последний раз открывал раздел
 * Обновлений». Источник для бейджа непрочитанных в навигации.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('users', 'updates_seen_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('updates_seen_at')->nullable()->after('dashboard_period_days');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'updates_seen_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('updates_seen_at');
        });
    }
};
