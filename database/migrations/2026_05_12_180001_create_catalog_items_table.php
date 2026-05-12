<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Реплика корпоративного каталога запчастей (MDB, локальная сеть).
 *
 * Источник — Access .mdb на офисном Windows. Прод не имеет к нему
 * доступа, поэтому данные доставляются push'ем: скрипт на офисной
 * машине читает MDB, шлёт snapshot в `POST /api/catalog/import`
 * (CatalogImportService), мы апсёртим по `sku` + `source_hash`.
 *
 * Soft-delete (is_active=false): если строка пропала из снапшота —
 * не удаляем физически, чтобы FK из request_items не валились и
 * чтобы можно было восстановить, если пропала случайно.
 *
 * Маппинг MDB → колонки см. CatalogImportService::normalizeRow().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalog_items')) {
            Schema::create('catalog_items', function (Blueprint $table) {
                $table->id();
                $table->string('sku', 64)->unique();
                $table->string('name', 500);
                $table->string('name_en', 500)->nullable();
                $table->string('unit_name', 128)->nullable();
                $table->string('part_type', 128)->nullable();
                $table->string('brand', 128)->nullable();
                $table->string('brand_article', 128)->nullable();
                $table->string('form_factor', 64)->nullable();

                // Габариты A..F и вес. Numeric для нормальных сравнений
                // и сортировок (не float — точные .3 после запятой).
                $table->decimal('size_a', 10, 3)->nullable();
                $table->decimal('size_b', 10, 3)->nullable();
                $table->decimal('size_c', 10, 3)->nullable();
                $table->decimal('size_d', 10, 3)->nullable();
                $table->decimal('size_e', 10, 3)->nullable();
                $table->decimal('size_f', 10, 3)->nullable();
                $table->decimal('weight', 10, 3)->nullable();

                $table->decimal('price', 12, 2)->nullable();
                $table->integer('stock_available')->nullable();

                // sha256 от значимых полей строки — позволяет на апсёрте
                // трогать только реально изменившиеся записи (UPDATE ...
                // WHERE source_hash != excluded.source_hash).
                $table->char('source_hash', 64);

                // Soft-delete: строки, отсутствующие в очередном snapshot,
                // помечаются is_active=false. Если появятся снова — обратно
                // в true. Жёстко не удаляем, чтобы исторические resolutions
                // у RequestItem'ов не теряли след.
                $table->boolean('is_active')->default(true);

                $table->timestamp('last_imported_at')->nullable();
                $table->foreignId('last_import_id')
                    ->nullable()
                    ->constrained('catalog_imports')
                    ->nullOnDelete();

                $table->timestamps();

                $table->index('brand', 'catalog_items_brand_idx');
                $table->index('brand_article', 'catalog_items_brand_article_idx');
                $table->index('is_active', 'catalog_items_active_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
