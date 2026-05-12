<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Нормализованный артикул производителя для быстрого матчинга (Phase 2 use-case B).
 *
 * Клиент в письме пишет артикулы в разной форме:
 *   "3RT2016-2GG22", "3rt2016 2gg22", "3RT2016/2GG22", "3RT20162GG22".
 * Каталог хранит каноническую форму. Чтобы сравнивать без on-the-fly regex
 * на каждую позицию, прекомпилим: убираем `[\s\-_./]`, делаем upper-case,
 * храним в `brand_article_normalized`. Lookup идёт по этой колонке с
 * b-tree индексом. Должно совпадать с `RequestItemParsingService::normalizeArticle`.
 *
 * Backfill через regexp_replace + upper делается в up(). Для 30k строк
 * пробегает за миллисекунды.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('catalog_items', 'brand_article_normalized')) {
            Schema::table('catalog_items', function (Blueprint $table) {
                $table->string('brand_article_normalized', 128)
                    ->nullable()
                    ->after('brand_article');
                $table->index('brand_article_normalized', 'catalog_items_brand_article_norm_idx');
            });

            DB::statement(<<<'SQL'
                UPDATE catalog_items
                SET brand_article_normalized = upper(regexp_replace(brand_article, '[\s\-_./]', '', 'g'))
                WHERE brand_article IS NOT NULL AND brand_article <> ''
            SQL);
        }
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            if (Schema::hasColumn('catalog_items', 'brand_article_normalized')) {
                $table->dropIndex('catalog_items_brand_article_norm_idx');
                $table->dropColumn('brand_article_normalized');
            }
        });
    }
};
