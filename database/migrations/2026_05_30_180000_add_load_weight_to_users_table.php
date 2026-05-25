<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Плановая нагрузка менеджера в процентах относительно стандарта.
 *
 *   100 — нормальная доля заявок (default).
 *    50 — в 2 раза меньше заявок чем у менеджера с weight=100.
 *   200 — в 2 раза больше.
 *
 * Используется AssignmentService::pickWeightedLeastLoadedManager:
 *   effective_load = load / (load_weight / 100)
 * Менеджер с min(effective_load) получает следующую заявку.
 *
 * Применяется ТОЛЬКО на стадии round-robin (sticky не трогаем —
 * там вес обязан проиграть бизнес-правилу «один клиент = один менеджер»).
 *
 * Защита от 0: CHECK >= 10 (минимум). При 0 деление обрушит расчёт.
 * Верхний предел 500 — защита от случайного клика «3500%».
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'load_weight')) {
                $table->smallInteger('load_weight')->default(100)->after('phone_extension');
            }
        });

        // Postgres CHECK
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_load_weight_range_chk CHECK (load_weight BETWEEN 10 AND 500)');
    }

    public function down(): void
    {
        // Drop constraint first (tolerant — может не существовать).
        try {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_load_weight_range_chk');
        } catch (\Throwable) {
        }
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'load_weight')) {
                $table->dropColumn('load_weight');
            }
        });
    }
};
