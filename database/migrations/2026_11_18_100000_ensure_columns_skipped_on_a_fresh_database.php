<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Колонки, которые на чистой базе не появлялись: миграция, добавлявшая колонку, по дате
 * шла раньше миграции, создающей таблицу, не находила таблицу и проходила вхолостую,
 * хотя записывалась выполненной. На старом проде таблицы создавались раньше, и колонки
 * были. Найдено при восстановлении после потери базы (ноябрь 2026) сверкой $fillable
 * моделей с колонками: без них ParseOutboundQuoteJob и ретрозагрузка падали на вставке.
 *
 *  - outbound_quote_items.base_unit_price, discount_percent — из 2026_05_18_120000,
 *    таблица создаётся в 2026_05_22_200100;
 *  - clarification_questions.target_slot_key — из 2026_05_14_180000, таблица создаётся
 *    в 2026_05_20_120000.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outbound_quote_items')) {
            Schema::table('outbound_quote_items', function (Blueprint $table) {
                if (! Schema::hasColumn('outbound_quote_items', 'base_unit_price')) {
                    $table->decimal('base_unit_price', 14, 4)->nullable()->after('unit_price');
                }
                if (! Schema::hasColumn('outbound_quote_items', 'discount_percent')) {
                    $table->decimal('discount_percent', 5, 2)->nullable()->after('base_unit_price');
                }
            });
        }

        if (Schema::hasTable('clarification_questions') && ! Schema::hasColumn('clarification_questions', 'target_slot_key')) {
            Schema::table('clarification_questions', function (Blueprint $table) {
                $table->string('target_slot_key', 64)->nullable()->after('question');
                $table->index('target_slot_key');
            });
        }
    }

    public function down(): void
    {
        // Колонки принадлежат исходным миграциям (2026_05_18_120000, 2026_05_14_180000):
        // откат этой их не трогает.
    }
};
