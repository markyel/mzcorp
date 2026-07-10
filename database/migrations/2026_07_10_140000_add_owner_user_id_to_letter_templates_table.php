<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Персонализация библиотеки шаблонов писем.
 *
 * Изначально дерево шаблонов было общим (shared). По требованию библиотека
 * должна быть ЛИЧНОЙ: каждый менеджер видит и правит только свои шаблоны.
 * Добавляем owner_user_id и фильтруем всё дерево по нему.
 *
 * Backfill: владельцем существующих узлов становится их создатель
 * (аудит-поле created_by_user_id). Узлы без создателя остаются orphan
 * (owner NULL) — их никто не увидит, что для тест-данных приемлемо.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('letter_templates', 'owner_user_id')) {
                $table->foreignId('owner_user_id')->nullable()
                    ->constrained('users')->cascadeOnDelete()
                    ->comment('Владелец узла (личная библиотека). NULL = orphan');
                $table->index('owner_user_id');
            }
        });

        // Владелец = создатель. Для Postgres ссылку на колонку даём через raw.
        DB::table('letter_templates')
            ->whereNull('owner_user_id')
            ->update(['owner_user_id' => DB::raw('created_by_user_id')]);
    }

    public function down(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            if (Schema::hasColumn('letter_templates', 'owner_user_id')) {
                $table->dropConstrainedForeignId('owner_user_id');
            }
        });
    }
};
