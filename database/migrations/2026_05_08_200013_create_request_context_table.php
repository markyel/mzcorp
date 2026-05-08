<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Документ 2 §4.1: контекст заявки целиком.
 *
 * Хранит надзаявочную информацию: какие единицы оборудования упомянуты,
 * из каких источников могут быть артикулы, общие свойства заявки.
 *
 * Заполняется AnalyzeRequestContextJob после создания request + items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_context', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('request_id')
                ->constrained('requests')
                ->cascadeOnDelete();

            $table->jsonb('equipment_units')->default('[]');
            $table->jsonb('mentioned_sources')->default('[]');
            $table->jsonb('metadata')->default('{}');

            $table->enum('analysis_status', ['pending', 'completed', 'partial', 'failed'])
                ->default('pending');
            $table->text('error_message')->nullable();
            $table->jsonb('llm_raw_response')->nullable();
            $table->string('llm_model_version')->nullable();
            $table->timestamp('analyzed_at')->nullable();

            $table->timestamps();

            $table->unique('request_id');
            $table->index('analysis_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_context');
    }
};
