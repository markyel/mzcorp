<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KB §3.1: детальные категории идентификации.
 *
 * Используются модулем оценки качества заявок. Грубые категории (19 шт.)
 * остаются жить в request_items.category — не трогаем.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('slug')->unique();
            $table->string('name');

            $table->jsonb('compatible_equipment')->default('["lift"]');
            $table->boolean('is_industry_specific')->default(false);
            $table->jsonb('synonyms')->default('[]');
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_categories');
    }
};
