<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Позиции исходящего КП/счёта.
 *
 * Заполняется `ParseOutboundQuoteJob` после `OutboundQuoteParsingService::parseWithGPT`.
 * Колонки соответствуют JSON-схеме промпта (LazyLift QuoteParsingService:
 * name, article, brand, quantity, unit_quantity, unit_measure, unit_price,
 * price, total, delivery_days, notes, is_analog).
 *
 * Линк к заявке:
 *  - `matched_catalog_item_id` — найден M-SKU в `catalog_items` через
 *    `OutboundQuoteItemMatcher::matchCatalog()` (нормализация артикулов
 *    через `CatalogImportService::cyrillicLookalikeFold`).
 *  - `matched_request_item_id` — best-match по M-SKU → catalog_item_id +
 *    fuzzy article/name + LLM fallback (Step 4 в matcher'е).
 *  - `match_score` 0..1, `match_source` enum для аудита.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outbound_quote_items')) {
            return;
        }

        Schema::create('outbound_quote_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('outbound_quote_id')
                ->constrained('outbound_quotes')->cascadeOnDelete();

            $table->unsignedInteger('position');

            // Сырые поля из LLM-парсера.
            $table->string('raw_name', 1000);
            $table->string('raw_article', 128)->nullable();
            $table->string('raw_brand', 128)->nullable();

            // Количественные поля. LazyLift-семантика:
            //   quantity — фактическое к-во (шт / м / кг);
            //   unit_measure — 'шт.' для штучного, 'м'/'кг'/etc для мерного;
            //   unit_quantity — для мерного: число метров/кг в одной строке;
            //   unit_price — цена за 1 единицу (1 шт / 1 м / 1 кг);
            //   line_total — итог за строку.
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('unit_measure', 32)->nullable();
            $table->decimal('unit_quantity', 14, 3)->nullable();
            $table->decimal('unit_price', 14, 4)->nullable();
            $table->decimal('line_price', 14, 2)->nullable(); // price (per piece/line)
            $table->decimal('line_total', 14, 2)->nullable(); // total = price × quantity

            $table->integer('delivery_days')->nullable();
            $table->boolean('is_analog')->default(false);
            $table->string('notes', 1000)->nullable();

            // Привязка к каталогу/заявке после OutboundQuoteItemMatcher.
            $table->foreignId('matched_catalog_item_id')->nullable()
                ->constrained('catalog_items')->nullOnDelete();
            $table->foreignId('matched_request_item_id')->nullable()
                ->constrained('request_items')->nullOnDelete();

            $table->float('match_score')->nullable(); // 0..1
            // 'sku_exact' | 'catalog_to_request' | 'fuzzy_article'
            //   | 'fuzzy_name' | 'llm' | 'unmatched'
            $table->string('match_source', 32)->nullable();
            $table->string('match_reason', 500)->nullable();

            // Доп. данные парсера: cited_chunk, raw_html_row, qty_available, и т.п.
            $table->jsonb('payload')->nullable();

            $table->timestamps();

            $table->index(['outbound_quote_id', 'position'], 'outbound_quote_items_quote_pos_idx');
            $table->index('matched_request_item_id', 'outbound_quote_items_request_item_idx');
            $table->index('matched_catalog_item_id', 'outbound_quote_items_catalog_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_quote_items');
    }
};
