<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кэш разборов кодов позиций: «что это за код и чей» (KB-маска или ИИ-анализ).
 * kind: oem (артикул производителя) | model (обозначение модели/серии из
 * маркировки, не складской код) | internal (внутренний код клиента) |
 * fragment (обрывок/не код) | unknown. Один раз разобрали — знаем навсегда;
 * используется отчётом «Не найдено в каталоге» и (дальше) пайплайном приёма.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('article_code_insights')) {
            return;
        }
        Schema::create('article_code_insights', function (Blueprint $table) {
            $table->id();
            $table->string('code_normalized', 64)->unique();
            $table->string('raw_sample', 190)->nullable();
            $table->string('kind', 16)->index(); // oem|model|internal|fragment|unknown
            $table->string('manufacturer_name', 120)->nullable();
            $table->foreignId('manufacturer_brand_id')->nullable()->constrained('manufacturer_brands')->nullOnDelete();
            $table->decimal('confidence', 4, 2)->nullable();
            $table->string('series_hint', 190)->nullable();
            $table->string('source', 16)->default('llm'); // kb_pattern|llm|manual
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('article_code_insights')) {
            Schema::drop('article_code_insights');
        }
    }
};
