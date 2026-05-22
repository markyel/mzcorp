<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * peak_status — «дальше всего достигнутый» milestone в lifecycle заявки.
 *
 * Контекст: state machine разрешает «откаты» (Quoted → InProgress «возврат
 * на правки»), и operational status может уйти назад. Менеджер при этом
 * хочет видеть в чипе milestone — «КП отправлено», а не текущий рабочий
 * шаг «В работе».
 *
 * Backfill через `php artisan requests:backfill-peak-status` (отдельная
 * команда — пробегает request_state_changes и считает max).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (! Schema::hasColumn('requests', 'peak_status')) {
                $table->string('peak_status', 64)->nullable()->after('status');
                $table->index('peak_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (Schema::hasColumn('requests', 'peak_status')) {
                $table->dropIndex(['peak_status']);
                $table->dropColumn('peak_status');
            }
        });
    }
};
