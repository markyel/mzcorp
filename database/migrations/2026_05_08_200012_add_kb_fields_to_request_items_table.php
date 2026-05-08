<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KB §5: расширение request_items полями результата работы модуля оценки качества.
 *
 * Поля заполняются на следующих этапах (документ 3), но колонки нужны уже сейчас.
 *
 * ВАЖНО: nullOnDelete на FK — критично для будущих операций дробления/объединения категорий.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            if (!Schema::hasColumn('request_items', 'identification_category_id')) {
                // У нас нет coarse-колонки `category` (LazyLift её добавляет
                // отдельной миграцией). Якоримся на parsed_unit — последнее
                // parsed-поле в нашей schema.
                $table->foreignId('identification_category_id')
                    ->nullable()
                    ->after('parsed_unit')
                    ->constrained('equipment_categories')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('request_items', 'manufacturer_brand_id')) {
                $table->foreignId('manufacturer_brand_id')
                    ->nullable()
                    ->after('identification_category_id')
                    ->constrained('manufacturer_brands')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('request_items', 'quality_assessment_status')) {
                $table->enum('quality_assessment_status', [
                    'not_assessed',
                    'sufficient',
                    'insufficient',
                    'not_covered',
                    'assessment_failed',
                ])->default('not_assessed')->after('manufacturer_brand_id');
            }

            if (!Schema::hasColumn('request_items', 'quality_assessment_payload')) {
                $table->jsonb('quality_assessment_payload')->nullable()->after('quality_assessment_status');
            }
        });

        Schema::table('request_items', function (Blueprint $table) {
            $table->index('quality_assessment_status', 'request_items_qa_status_idx');
            $table->index('identification_category_id', 'request_items_id_category_idx');
        });
    }

    public function down(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            $table->dropIndex('request_items_qa_status_idx');
            $table->dropIndex('request_items_id_category_idx');
        });

        Schema::table('request_items', function (Blueprint $table) {
            if (Schema::hasColumn('request_items', 'quality_assessment_payload')) {
                $table->dropColumn('quality_assessment_payload');
            }
            if (Schema::hasColumn('request_items', 'quality_assessment_status')) {
                $table->dropColumn('quality_assessment_status');
            }
            if (Schema::hasColumn('request_items', 'manufacturer_brand_id')) {
                $table->dropConstrainedForeignId('manufacturer_brand_id');
            }
            if (Schema::hasColumn('request_items', 'identification_category_id')) {
                $table->dropConstrainedForeignId('identification_category_id');
            }
        });
    }
};
