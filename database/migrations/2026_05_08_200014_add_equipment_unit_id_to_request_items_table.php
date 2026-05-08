<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Документ 2 §4.2: привязка позиции к единице оборудования из request_context.equipment_units.
 *
 * Локальный для заявки идентификатор (строка типа "unit_1"). НЕ FK,
 * т.к. единицы хранятся как jsonb внутри request_context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            if (!Schema::hasColumn('request_items', 'equipment_unit_id')) {
                $table->string('equipment_unit_id')
                    ->nullable()
                    ->after('quality_assessment_payload');
            }
        });

        Schema::table('request_items', function (Blueprint $table) {
            $table->index('equipment_unit_id', 'request_items_equipment_unit_idx');
        });
    }

    public function down(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            $table->dropIndex('request_items_equipment_unit_idx');
        });

        Schema::table('request_items', function (Blueprint $table) {
            if (Schema::hasColumn('request_items', 'equipment_unit_id')) {
                $table->dropColumn('equipment_unit_id');
            }
        });
    }
};
