<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тип последнего значимого события по заявке (для колонки «Событие»
 * в Pool). Идёт парой с `last_activity_at`.
 *
 * Заполняется через RequestActivityService::touch($req, $type, $at).
 * NULL для исторических заявок (backfill не делаем — можно догадаться по
 * status'у, но это не точно; новые события заполнят естественным путём).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (! Schema::hasColumn('requests', 'last_activity_type')) {
                $table->string('last_activity_type', 40)->nullable()->after('last_activity_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (Schema::hasColumn('requests', 'last_activity_type')) {
                $table->dropColumn('last_activity_type');
            }
        });
    }
};
