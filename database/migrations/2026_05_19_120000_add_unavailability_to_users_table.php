<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation Фаза 2 — менеджер «недоступен» с переподчинением заявок.
 *
 * Менеджер уходит в отпуск/командировку → РОП помечает его «недоступен
 * до DD.MM.YYYY», система автоматически:
 *  1. Исключает из AssignmentService::autoAssign (новые заявки идут другим).
 *  2. Опционально — массовый ReassignAllFromManagerAction передаёт текущий
 *     пул другим активным менеджерам через round-robin.
 *
 * Поля:
 *  - unavailable_until — timestamp возврата. NULL = доступен.
 *  - unavailable_reason — текст «отпуск», «командировка», «больничный».
 *
 * Возврат:
 *  - Cron `users:reactivate-unavailable` (или ручной toggle в Admin/Managers)
 *    обнуляет поля когда unavailable_until <= now().
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'unavailable_until')) {
                $table->timestamp('unavailable_until')->nullable()->after('archived_at');
            }
            if (! Schema::hasColumn('users', 'unavailable_reason')) {
                $table->string('unavailable_reason', 500)->nullable()->after('unavailable_until');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['unavailable_reason', 'unavailable_until'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
