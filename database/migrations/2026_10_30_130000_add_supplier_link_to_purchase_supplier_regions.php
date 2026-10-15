<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Карточка справочника, к которой снабжение привязало поставщика 1С («Привязать к карточке»):
        // главнее совпадения по названию и при следующих загрузках «Закупок 1С».
        if (! Schema::hasColumn('purchase_supplier_regions', 'supplier_id')) {
            Schema::table('purchase_supplier_regions', function (Blueprint $table) {
                $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_supplier_regions', 'supplier_id')) {
            Schema::table('purchase_supplier_regions', fn (Blueprint $table) => $table->dropConstrainedForeignId('supplier_id'));
        }
    }
};
