<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Алиасы параметров идентификации.
 *
 * n8n-парсер кладёт извлечённые параметры в request_items.parsed_params
 * под русскими ключами («диаметр», «ширина», «напряжение»), а KB-правила
 * требуют английские slug'и («diameter_mm», «width_mm», «coil_voltage_v»).
 *
 * Aliases решают этот разрыв без изменения n8n-промпта:
 * QualityAssessmentService при сборе available_parameters проверяет
 * каждый ключ из parsed_params, и если он совпадает с alias какого-то
 * параметра — кладёт значение под canonical slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identification_parameters', function (Blueprint $table) {
            if (!Schema::hasColumn('identification_parameters', 'aliases')) {
                $table->jsonb('aliases')->default('[]')->after('allowed_values');
            }
        });
    }

    public function down(): void
    {
        Schema::table('identification_parameters', function (Blueprint $table) {
            if (Schema::hasColumn('identification_parameters', 'aliases')) {
                $table->dropColumn('aliases');
            }
        });
    }
};
