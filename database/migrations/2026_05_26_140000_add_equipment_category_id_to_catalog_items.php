<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Прямая связь catalog_items → equipment_categories (Phase B/2026-05-21).
 *
 * Заменяет хрупкую substring-фильтрацию по synonyms (Catalog\Search) на
 * точный FK-фильтр. Заполнение поля — командой `kb:backfill-categories`
 * (rule-based по synonyms + LLM-fallback для непокрытых SKU).
 *
 * Nullable: legacy SKU могут оставаться без категории до прогона backfill.
 * Поиск/фильтр учитывает NULL как «без типа» (опционально показывается
 * как ещё один отдельный пункт).
 *
 * ON DELETE SET NULL: если KB-категорию удалят, catalog_items не сломается.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalog_items')) {
            return;
        }
        Schema::table('catalog_items', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_items', 'equipment_category_id')) {
                $table->foreignId('equipment_category_id')
                    ->nullable()
                    ->after('part_type')
                    ->constrained('equipment_categories')
                    ->nullOnDelete();
                $table->index('equipment_category_id', 'catalog_items_equipment_category_id_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('catalog_items')) {
            return;
        }
        Schema::table('catalog_items', function (Blueprint $table) {
            if (Schema::hasColumn('catalog_items', 'equipment_category_id')) {
                $table->dropForeign(['equipment_category_id']);
                $table->dropIndex('catalog_items_equipment_category_id_idx');
                $table->dropColumn('equipment_category_id');
            }
        });
    }
};
