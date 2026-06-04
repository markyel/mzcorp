<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GIN pg_trgm индексы на английское название `name_en` каталога.
 *
 * До этого текстовый поиск (`/dashboard/catalog/search` + модалка manual-link
 * в карточке заявки) ходил по sku / name / brand_article_normalized /
 * articles_search / brands_search / description / comment, но НЕ по `name_en`.
 * Из-за этого артикул, спрятанный в английском названии (напр. «LOP push
 * button (A4N59074) ARROW UP …» при sku=M26748), не давал твёрдого
 * code-token / trigram матча — позиция проваливалась в выдаче ниже тех, где
 * тот же код лежит в structured-артикуле. Тикет: запрос «A4N59074 стрелка».
 *
 * Два индекса под два выражения, которые использует поиск:
 *   - lower(name_en)                              — CatalogSearchService (raw ILIKE);
 *   - regexp_replace(lower(name_en), '[-_./,]')   — code-token / trigram (nosep),
 *     совпадает с выражением для `name` в CatalogEmbeddingService.
 *
 * pg_trgm уже включён (см. MEMORY § pgvector/pg_trgm whitelist).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('catalog_items')) {
            return;
        }

        DB::statement(
            "CREATE INDEX IF NOT EXISTS catalog_items_name_en_trgm_idx
             ON catalog_items USING gin (lower(name_en) gin_trgm_ops)"
        );

        DB::statement(
            "CREATE INDEX IF NOT EXISTS catalog_items_name_en_nosep_trgm_idx
             ON catalog_items USING gin (regexp_replace(lower(name_en), '[\\-_./,]', '', 'g') gin_trgm_ops)"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS catalog_items_name_en_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS catalog_items_name_en_nosep_trgm_idx');
    }
};
