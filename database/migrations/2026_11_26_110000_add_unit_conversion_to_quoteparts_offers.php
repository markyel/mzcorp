<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пересчёт цены поставщика на нашу единицу (18.11.2026, M31632): OTI дал 62,90 USD за одну ступень, а позиция —
 * комплект на 10 ступеней; в 1С ушла бы цена в 10 раз ниже. Снабжение указывает «цена за unit_from_qty — нужна за
 * unit_to_qty»: в выгрузку и выбор идёт price × to / from, цена поставщика остаётся в комментарии.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quoteparts_offers')) {
            return;
        }
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('quoteparts_offers', 'unit_from_qty')) {
                $table->decimal('unit_from_qty', 12, 3)->nullable();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'unit_to_qty')) {
                $table->decimal('unit_to_qty', 12, 3)->nullable();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'unit_set_by_user_id')) {
                $table->foreignId('unit_set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quoteparts_offers')) {
            return;
        }
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (Schema::hasColumn('quoteparts_offers', 'unit_set_by_user_id')) {
                $table->dropConstrainedForeignId('unit_set_by_user_id');
            }
            foreach (['unit_from_qty', 'unit_to_qty'] as $column) {
                if (Schema::hasColumn('quoteparts_offers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
