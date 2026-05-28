<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GIN pg_trgm индексы на `description` и `comment` — для расширения
 * поиска по каталогу (`/dashboard/catalog/search` + модалка manual-link
 * в карточке заявки).
 *
 * До этого поиск ходил только по sku / brand_article_normalized /
 * articles_search / brands_search / name. Информация о замещающих
 * OEM-кодах (типичный `comment`: «ЗАМЕНА ДЛЯ B157AAEX01») и о
 * характеристиках товара (`description`) оставалась невидимой.
 *
 * Vector embedding (`catalog_item_embeddings`) описание/комментарий
 * не покрывает — это потребовало бы перегенерации 35K эмбеддингов.
 * Здесь добавляем только текст-поиск через ILIKE + word_similarity.
 *
 * pg_trgm в Beget whitelist (см. MEMORY § «pgvector whitelist»),
 * `CREATE EXTENSION` уже выполнен — нужны только индексы.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('catalog_items')) {
            return;
        }

        // Idempotent — IF NOT EXISTS гасит повторные запуски.
        // CONCURRENTLY НЕ ставим — миграция должна быть транзакционной.
        // На 35K строк индекс GIN trgm строится секунд за 10-20.
        DB::statement(
            "CREATE INDEX IF NOT EXISTS catalog_items_description_trgm_idx
             ON catalog_items
             USING GIN (lower(description) gin_trgm_ops)
             WHERE description IS NOT NULL AND description <> ''"
        );

        DB::statement(
            "CREATE INDEX IF NOT EXISTS catalog_items_comment_trgm_idx
             ON catalog_items
             USING GIN (lower(comment) gin_trgm_ops)
             WHERE comment IS NOT NULL AND comment <> ''"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS catalog_items_description_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS catalog_items_comment_trgm_idx');
    }
};
