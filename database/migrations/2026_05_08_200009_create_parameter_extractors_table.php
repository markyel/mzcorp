<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KB §3.9: универсальный движок извлечения параметров из строкового поля позиции.
 *
 * Покрывает оба случая:
 *  - декодирование структурированных артикулов производителя (LC1D09M7 → ток, напряжение)
 *  - парсинг свободной маркировки (Канат ∅8мм 6×19 о.с. → диаметр, конструкция)
 *
 * Различаются эти случаи только полем-источником (source_field) и условием применения,
 * движок один.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parameter_extractors', function (Blueprint $table) {
            $table->bigIncrements('id');

            // === Условия применения ===
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('equipment_categories')
                ->cascadeOnDelete();
            $table->foreignId('brand_id')
                ->nullable()
                ->constrained('manufacturer_brands')
                ->cascadeOnDelete();

            $table->enum('source_field', ['article', 'name', 'raw_text']);

            $table->foreignId('triggered_by_sku_pattern_id')
                ->nullable()
                ->constrained('brand_sku_patterns')
                ->cascadeOnDelete();

            // === Что извлекается ===
            $table->jsonb('rules');

            // === Подготовка и нормализация ===
            $table->jsonb('pre_normalize_rules')->default('[]');
            $table->jsonb('post_extract_rules')->default('{}');

            // === Тесты и метаданные ===
            $table->jsonb('test_examples')->default('[]');
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();

            $table->timestamps();

            $table->index(['category_id', 'source_field', 'is_active']);
            $table->index(['brand_id', 'source_field']);
            $table->index(['source_field']);
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parameter_extractors');
    }
};
