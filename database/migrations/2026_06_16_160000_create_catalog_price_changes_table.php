<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История изменения цен каталожных позиций (аналитика трендов).
 *
 * Пишется `CatalogImportService::import()` при апсёрте, когда у существующей
 * SKU поменялась `price` и/или `price_min` (было → стало). По этой таблице
 * видно, на что цена росла, а на что падала со временем.
 *
 * Каталог = master data (read-only в MyLift), поэтому единственный источник
 * изменений — импорт снапшота MDB. Одна строка = один зафиксированный переход
 * цены конкретной SKU в рамках конкретного импорта.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('catalog_price_changes')) {
            return;
        }

        Schema::create('catalog_price_changes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('catalog_item_id')
                ->constrained('catalog_items')->cascadeOnDelete();

            // Денормализованный SKU — чтобы строить отчёты/искать без join'а
            // и сохранить читаемость записи даже при ребилде каталога.
            $table->string('sku', 64)->index();

            // было → стало. Nullable: цена могла отсутствовать с любой стороны
            // (новый прайс без цены / снятие цены).
            $table->decimal('old_price', 12, 2)->nullable();
            $table->decimal('new_price', 12, 2)->nullable();
            $table->decimal('old_price_min', 12, 2)->nullable();
            $table->decimal('new_price_min', 12, 2)->nullable();

            // Источник изменения — импорт-снапшот (аудит).
            $table->foreignId('import_id')->nullable()
                ->constrained('catalog_imports')->nullOnDelete();

            // Дата фиксации перехода (= момент импорта). Индекс для аналитики
            // «за период».
            $table->timestamp('changed_at')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_price_changes');
    }
};
