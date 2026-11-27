<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Формулировки клиентов, подтверждённые карточкой каталога: «как клиенты называли эту позицию».
 *
 * Подбор по названию (CatalogEmbeddingService::matchByRequestItem) ищет кандидатов не только по названиям
 * каталога 1С, но и по прошлым формулировкам клиентов, и показывает их модели-реранку как примеры. Проверка
 * на истории (27.11.2026, 120 позиций): правильная карточка в кандидатах 46 → 63, верных привязок 9 → 21.
 *
 * Источник — позиции заявок, чью карточку подтвердили наш M-артикул, артикул производителя, КП менеджера или
 * ручная привязка (CatalogClientPhraseService::sync). Каталог 1С не трогаем: словарь живёт в нашей БД.
 * Вектор — та же модель, что catalog_item_embeddings (text-embedding-3-small, 1536).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_client_phrases')) {
            return;
        }

        Schema::create('catalog_client_phrases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->index()->constrained('catalog_items')->cascadeOnDelete();
            $table->foreignId('request_item_id')->unique()->constrained('request_items')->cascadeOnDelete();
            $table->unsignedBigInteger('request_id')->index();
            $table->text('phrase');
            $table->text('query_text');
            $table->string('source', 32);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE catalog_client_phrases ADD COLUMN embedding vector(1536) NOT NULL');
        DB::statement('CREATE INDEX catalog_client_phrases_hnsw_idx ON catalog_client_phrases USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        if (Schema::hasTable('catalog_client_phrases')) {
            DB::statement('DROP INDEX IF EXISTS catalog_client_phrases_hnsw_idx');
            Schema::drop('catalog_client_phrases');
        }
    }
};
