<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Цель вопроса справочника опознания — какие данные он должен дать («Фото маркировки» → модель/маркировка с
 * корпуса). По цели проверяется, есть ли ответ у позиции уже (заказчик 29.11.2026: фото с читаемой
 * маркировкой — ответ полный, фото без маркировки — неполный).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('identification_hints') && ! Schema::hasColumn('identification_hints', 'goal')) {
            Schema::table('identification_hints', function (Blueprint $table) {
                $table->text('goal')->nullable()->after('question');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('identification_hints') && Schema::hasColumn('identification_hints', 'goal')) {
            Schema::table('identification_hints', function (Blueprint $table) {
                $table->dropColumn('goal');
            });
        }
    }
};
