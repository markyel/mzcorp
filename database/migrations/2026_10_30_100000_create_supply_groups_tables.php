<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Регион поставщика из «Закупок 1С» (как он назван в 1С): cn / ru / eu. Определяется
        // автоматически по названию и валюте, снабжение может поправить (source = manual).
        if (! Schema::hasTable('purchase_supplier_regions')) {
            Schema::create('purchase_supplier_regions', function (Blueprint $table) {
                $table->id();
                $table->string('supplier_name_1c', 500)->unique();
                $table->string('region', 8)->nullable()->index();
                $table->string('source', 16)->default('auto');
                $table->unsignedInteger('items_count')->default(0);
                $table->jsonb('currencies')->nullable();
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // Группы позиции каталога (Китай / Россия / Европа): по регионам её поставщиков 1С,
        // или заданные руками (source = manual — пересчёт их не трогает).
        if (! Schema::hasTable('catalog_item_supply_groups')) {
            Schema::create('catalog_item_supply_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('catalog_item_id')->constrained('catalog_items')->cascadeOnDelete();
                $table->string('group_code', 8);
                $table->string('source', 16)->default('auto');
                $table->timestamps();

                $table->unique(['catalog_item_id', 'group_code']);
                $table->index('group_code');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_item_supply_groups');
        Schema::dropIfExists('purchase_supplier_regions');
    }
};
