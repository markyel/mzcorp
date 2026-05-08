<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coarse-категория позиции (одна из 19 из CoarseCategories::ALL).
 *
 * Поле необходимо для активации LLM-pathway в CategoryRefinementService:
 * при наличии coarse сервис фильтрует fine-кандидатов через
 * `whereHas('coarseCategories')`, и при 2+ кандидатах переходит к
 * refineWithLlm() — настоящему gpt-4o вызову. Без coarse сервис уходит
 * в fallback synonym-match (regex), что даёт класс false-positives через
 * substring overlap (см. сессия 2026-05-08).
 *
 * Заполняется парсером позиций (ParseItemsPrompt v5) при создании/обновлении
 * RequestItem. Старые items с NULL остаются в regex-pathway до очередного
 * парсинга или batch-coarse-resolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            if (! Schema::hasColumn('request_items', 'category')) {
                $table->string('category', 64)->nullable()->after('parsed_unit')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            if (Schema::hasColumn('request_items', 'category')) {
                $table->dropIndex(['category']);
                $table->dropColumn('category');
            }
        });
    }
};
