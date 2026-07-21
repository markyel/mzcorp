<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ослабляем нижнюю границу плановой нагрузки с 10 до 1.
 *
 * Изначально CHECK был `BETWEEN 10 AND 500` — «защита от деления на ноль» в
 * Assignment::pickWeightedLeastLoadedManager (effective_load = load /
 * (load_weight/100)). Но сам расчёт уже клампит вес `max(1, min(500, …))`,
 * поэтому значение 1 безопасно, а РОП хочет ставить редким менеджерам 1–5%
 * (иначе вставало 500 из-за конфликта валидации приложения и констрейнта).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_load_weight_range_chk');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_load_weight_range_chk CHECK (load_weight BETWEEN 1 AND 500)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_load_weight_range_chk');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_load_weight_range_chk CHECK (load_weight BETWEEN 10 AND 500)');
    }
};
