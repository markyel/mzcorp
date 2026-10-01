<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Дополнительная (проектная) задача — отдельный раздел отчёта по форме
 * Приложения № 2 к договору: задача, стадия, результат. Обычные записи
 * журнала идут в регулярные услуги.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('marketing_entries', 'is_project')) {
            Schema::table('marketing_entries', function (Blueprint $table) {
                $table->boolean('is_project')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('marketing_entries', 'is_project')) {
            Schema::table('marketing_entries', function (Blueprint $table) {
                $table->dropColumn('is_project');
            });
        }
    }
};
