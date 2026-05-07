<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Позиции заявки (Foundation §«RequestItem», 2-10 позиций/заявка).
 *
 * Phase 1.8b минимум: parsed_* поля для RequestItemParsingService
 * (drop-in из LazyLift). KB-расширения (identification_category_id,
 * manufacturer_brand_id, quality_assessment_*, equipment_unit_id,
 * catalog_matches, piece_size/piece_unit) — Phase 2.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1)
                ->comment('Порядковый номер позиции в заявке');

            $table->string('parsed_name', 255)
                ->comment('Самодостаточное название: тип + бренд + ключевые параметры');
            $table->string('parsed_brand')->nullable();
            $table->string('parsed_article')->nullable()->index();
            $table->decimal('parsed_qty', 12, 3)->default(1);
            $table->string('parsed_unit', 32)->default('шт.');
            $table->text('supplier_note')->nullable()
                ->comment('Вторичная размерность ("113 м каждый") или пометка из исходного документа');

            $table->string('data_source', 32)->default('email_attachment')
                ->comment('email_attachment | email_body | email_image | manual');
            $table->string('status', 32)->default('parsed')->index()
                ->comment('parsed | confirmed | rejected | matched (Phase 2+)');
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->index(['request_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_items');
    }
};
