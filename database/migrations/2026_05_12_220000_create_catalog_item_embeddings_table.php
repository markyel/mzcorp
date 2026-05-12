<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * pgvector-эмбеддинги для семантического матчинга позиций по name (Phase 2 use-case C).
 *
 * Когда A (M-SKU) и B (brand_article) не нашли — Catalog\CatalogResolutionService::
 * matchByName генерит embedding `parsed_name + brand + part_type` запроса и
 * ищет ближайший catalog_items по cosine similarity. См. CatalogEmbeddingService
 * и docs/embedding_text_recipe.
 *
 * Технические решения:
 *  - vector(1536) — text-embedding-3-small (дефолт). При смене модели на
 *    -large (3072) → миграция ALTER TABLE; пока ставим 1536.
 *  - HNSW индекс по cosine — быстрый ANN. Для 30k items дает <10ms на query.
 *    Альтернатива IVFFlat — лучше для 1M+ items.
 *  - source_hash — sha256 от text, который шёл на embed. Позволяет
 *    incremental обновление: если хеш не менялся — не дёргаем OpenAI.
 *  - source_text хранится для аудита/дебага (видим точный input, не
 *    реверс-инжинеря из catalog_items).
 *  - UNIQUE(catalog_item_id) — на каждый catalog_item ровно один embedding.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_item_embeddings')) {
            return;
        }

        // Создаём таблицу без vector — добавим колонку отдельным statement,
        // потому что Laravel\Blueprint в нужной версии не имеет helper'а для
        // vector-типа pgvector.
        Schema::create('catalog_item_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')
                ->unique()
                ->constrained('catalog_items')
                ->cascadeOnDelete();
            $table->char('source_hash', 64);
            $table->text('source_text');
            $table->string('model_version', 64);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE catalog_item_embeddings ADD COLUMN embedding vector(1536) NOT NULL');

        // HNSW индекс. m=16 (среднее качество/память), ef_construction=64.
        // На 30k items строится за ~1-2с, потом query latency ~5-10ms.
        DB::statement('CREATE INDEX catalog_item_embeddings_hnsw_idx ON catalog_item_embeddings USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        if (Schema::hasTable('catalog_item_embeddings')) {
            DB::statement('DROP INDEX IF EXISTS catalog_item_embeddings_hnsw_idx');
            Schema::drop('catalog_item_embeddings');
        }
    }
};
