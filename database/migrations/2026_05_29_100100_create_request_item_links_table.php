<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.1 — детальное соответствие позиций между parent и child
 * наследующих заявок.
 *
 * Drop-in из LazyLift `2026_04_21_100000_create_request_item_links_table`.
 *
 * Семантика: у child-item может быть только ОДНА активная связь с
 * каким-то parent-item (unique partial index по WHERE is_active=true).
 * История неактивных связей сохраняется — отвязки помечаются
 * `is_active=false`, не удаляются.
 *
 * `qty_ratio` — отношение child.parsed_qty / parent.parsed_qty (1.0
 * по умолчанию). Может пригодиться для аналитики «клиент уменьшил
 * объём» / «клиент увеличил».
 *
 * `mapping_source`: auto_article | auto_similarity | auto_llm | manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('request_item_links')) {
            return;
        }

        Schema::create('request_item_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_item_id')
                ->constrained('request_items')
                ->cascadeOnDelete();
            $table->foreignId('parent_item_id')
                ->constrained('request_items')
                ->cascadeOnDelete();
            $table->decimal('qty_ratio', 8, 2)->default(1);
            $table->string('mapping_source', 32);
            $table->decimal('mapping_confidence', 3, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('linked_by')->nullable();
            $table->timestamps();

            $table->index(['parent_item_id', 'is_active'], 'request_item_links_parent_active_idx');
            $table->index(['child_item_id', 'is_active'], 'request_item_links_child_active_idx');
        });

        // Один child-item — максимум одна активная связь.
        DB::statement(
            'CREATE UNIQUE INDEX request_item_links_unique_active_child '
            . 'ON request_item_links (child_item_id) WHERE is_active = true'
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('request_item_links')) {
            DB::statement('DROP INDEX IF EXISTS request_item_links_unique_active_child');
            Schema::dropIfExists('request_item_links');
        }
    }
};
