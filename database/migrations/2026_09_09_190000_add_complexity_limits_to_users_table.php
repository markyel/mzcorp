<?php

use App\Enums\ComplexityLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Потолок сложности заявок на менеджера (запрос РОПа 2026-09-09).
 *
 *  - max_complexity_level — заявки какого уровня менеджер получает в
 *    round-robin: NULL (по умолчанию) = без ограничения, 'easy' = только
 *    лёгкие, 'normal' = лёгкие и средние и т.д. По мере роста менеджера
 *    РОП поднимает потолок в карточке.
 *  - only_internal_sku_requests — жёсткий режим «только M-артикулы»: заявка
 *    подходит, если ВСЕ активные позиции пришли M-артикулом
 *    (request_items.match_path = internal_sku).
 *
 * Ограничение работает только на стадии round-robin; sticky («клиент написал
 * лично», «этот клиент уже мой») по-прежнему сильнее — см. AssignmentService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'max_complexity_level')) {
                $table->string('max_complexity_level', 20)->nullable()->after('load_weight');
            }
            if (! Schema::hasColumn('users', 'only_internal_sku_requests')) {
                $table->boolean('only_internal_sku_requests')->default(false)->after('max_complexity_level');
            }
        });

        if (Schema::hasColumn('users', 'max_complexity_level')) {
            $values = implode(',', array_map(
                fn (string $v) => "'".$v."'",
                ComplexityLevel::values(),
            ));
            $exists = collect(\Illuminate\Support\Facades\DB::select(
                'select 1 from pg_constraint where conname = ?',
                ['users_max_complexity_level_chk'],
            ))->isNotEmpty();
            if (! $exists) {
                \Illuminate\Support\Facades\DB::statement(
                    "ALTER TABLE users ADD CONSTRAINT users_max_complexity_level_chk
                     CHECK (max_complexity_level IS NULL OR max_complexity_level IN ({$values}))",
                );
            }
        }
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_max_complexity_level_chk');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'only_internal_sku_requests')) {
                $table->dropColumn('only_internal_sku_requests');
            }
            if (Schema::hasColumn('users', 'max_complexity_level')) {
                $table->dropColumn('max_complexity_level');
            }
        });
    }
};
