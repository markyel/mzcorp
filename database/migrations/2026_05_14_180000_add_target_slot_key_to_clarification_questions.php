<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation §6.2 Phase D — структурный target slot для каждого вопроса.
 *
 * Менеджер задаёт вопрос через slot's «+ спросить» или quick-chip —
 * мы помним, какой слот пытались заполнить. Когда клиент ответит, LLM-
 * матчер получит target_slot_key и создаст enrichment suggestion
 * напрямую на правильное поле (parsed_brand / kb:<slug> / etc.), а не
 * угадывает.
 *
 * Формат значения:
 *  - 'brand' | 'article' | 'qty' — базовые слоты (mapped в parsed_*).
 *  - 'kb:<slug>' — KB-параметр (lift_brand, lift_series, и т.д.).
 *  - NULL — вопрос без привязки (free-text или общий quick-chip).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('clarification_questions') &&
            ! Schema::hasColumn('clarification_questions', 'target_slot_key')) {
            Schema::table('clarification_questions', function (Blueprint $table) {
                $table->string('target_slot_key', 64)->nullable()->after('question');
                $table->index('target_slot_key');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('clarification_questions') &&
            Schema::hasColumn('clarification_questions', 'target_slot_key')) {
            Schema::table('clarification_questions', function (Blueprint $table) {
                $table->dropIndex(['target_slot_key']);
                $table->dropColumn('target_slot_key');
            });
        }
    }
};
