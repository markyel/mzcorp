<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Что из нужного для опознания детали у позиции уже есть (App\Services\Catalog\IdentificationCoverageService):
 * тип детали и по каждому вопросу справочника — получено / неполно / нет данных, значение и источник.
 * Отдельная колонка, не quality_assessment_payload: тот переписывается оценкой качества целиком.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('request_items') && ! Schema::hasColumn('request_items', 'identification_coverage')) {
            Schema::table('request_items', function (Blueprint $table) {
                $table->jsonb('identification_coverage')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('request_items') && Schema::hasColumn('request_items', 'identification_coverage')) {
            Schema::table('request_items', function (Blueprint $table) {
                $table->dropColumn('identification_coverage');
            });
        }
    }
};
