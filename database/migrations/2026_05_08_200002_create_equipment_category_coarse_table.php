<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KB §3.2: связь many-to-many детальная категория ↔ грубая (одна из 19).
 *
 * Список 19 грубых хранится в коде как константа CoarseCategories
 * (синхронизирован с NormalizeSupplierProfileJob::CATEGORIES).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_category_coarse', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('category_id')
                ->constrained('equipment_categories')
                ->cascadeOnDelete();
            $table->string('coarse_category');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['category_id', 'coarse_category']);
            $table->index('coarse_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_category_coarse');
    }
};
