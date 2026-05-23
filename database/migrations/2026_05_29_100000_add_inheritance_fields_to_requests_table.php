<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.1 — наследование заявок от архивных closed_lost.
 *
 * Когда клиент пишет письмо, которое матчит на закрытую заявку (linker
 * Levels 1-4), мы НЕ реанимируем её (Phase 1), но запускаем async
 * LLM-проверку: «новая заявка — это продолжение архивной X?». Если
 * подтверждено — новая Request становится `child`, архивная — `parent`,
 * связаны через inheritance_parent_id + общий inheritance_group_id (UUID).
 *
 * Архитектура — drop-in из LazyLift `2026_04_21_100001`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (! Schema::hasColumn('requests', 'inheritance_group_id')) {
                $table->uuid('inheritance_group_id')
                    ->nullable()
                    ->after('merged_at')
                    ->index();
            }
            if (! Schema::hasColumn('requests', 'inheritance_role')) {
                // 'parent' | 'child' | null
                $table->string('inheritance_role', 16)
                    ->nullable()
                    ->after('inheritance_group_id');
            }
            if (! Schema::hasColumn('requests', 'inheritance_parent_id')) {
                $table->foreignId('inheritance_parent_id')
                    ->nullable()
                    ->after('inheritance_role')
                    ->constrained('requests')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            if (Schema::hasColumn('requests', 'inheritance_parent_id')) {
                $table->dropConstrainedForeignId('inheritance_parent_id');
            }
            if (Schema::hasColumn('requests', 'inheritance_role')) {
                $table->dropColumn('inheritance_role');
            }
            if (Schema::hasColumn('requests', 'inheritance_group_id')) {
                $table->dropColumn('inheritance_group_id');
            }
        });
    }
};
