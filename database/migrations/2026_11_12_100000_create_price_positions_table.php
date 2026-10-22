<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Позиция «Актуализации цен» в работе снабжения: в списке выгрузки 1С, выгружена, сбор цен
        // завершён (позиция убрана с экрана до нового предложения). Строка — артикул позиции.
        if (! Schema::hasTable('price_positions')) {
            Schema::create('price_positions', function (Blueprint $table) {
                $table->id();
                $table->string('sku', 64)->unique();
                $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
                $table->timestamp('in_export_at')->nullable()->index();
                $table->foreignId('in_export_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('exported_at')->nullable();
                $table->foreignId('price_export_id')->nullable()->constrained('price_exports')->nullOnDelete();
                $table->timestamp('closed_at')->nullable()->index();
                $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                // Что выгрузили при завершении: предложение, поставщик, цена — для заметки «работа завершена».
                $table->jsonb('closed_offer')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('price_positions');
    }
};
