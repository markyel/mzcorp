<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Персональный дефолтный период дашборда (preset 1/7/30/90 дней).
 * Сохраняется per-user при переключении чипа периода и применяется при
 * следующем входе на дашборд — вместо жёсткого дефолта в 30 дней.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'dashboard_period_days')) {
                $table->unsignedSmallInteger('dashboard_period_days')->default(30);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'dashboard_period_days')) {
                $table->dropColumn('dashboard_period_days');
            }
        });
    }
};
