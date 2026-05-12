<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FK позиции заявки на конкретный товар каталога (Phase 2 use-case B + C).
 *
 * Заполняется:
 *   - CatalogResolutionService::resolveItem  — для M-SKU позиций (use-case A);
 *   - CatalogResolutionService::matchByArticle — для произвольных артикулов
 *     клиента, сматченных через brand_article (use-case B).
 *
 * Null — позиция ещё не сматчена / нет в каталоге.
 *
 * onDelete=set null: если каталожная строка ушла из MDB и физически
 * удалена (что мы вообще не делаем — soft-delete), позиция не валится.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('request_items', 'catalog_item_id')) {
            Schema::table('request_items', function (Blueprint $table) {
                $table->foreignId('catalog_item_id')
                    ->nullable()
                    ->after('image_attachment_id')
                    ->constrained('catalog_items')
                    ->nullOnDelete();
                $table->index('catalog_item_id', 'request_items_catalog_item_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            if (Schema::hasColumn('request_items', 'catalog_item_id')) {
                $table->dropIndex('request_items_catalog_item_idx');
                $table->dropConstrainedForeignId('catalog_item_id');
            }
        });
    }
};
