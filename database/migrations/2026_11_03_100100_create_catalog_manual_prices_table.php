<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Цена из ручной синхронизации держится, пока плановый импорт (выгрузка mylift, 6 раз в
        // сутки) отдаёт старую: иначе он через несколько часов откатил бы её. Снимается, когда
        // выгрузка догнала 1С (та же цена) или по сроку.
        if (Schema::hasTable('catalog_manual_prices')) {
            return;
        }
        Schema::create('catalog_manual_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->unique()->constrained('catalog_items')->cascadeOnDelete();
            $table->string('sku', 64)->index();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('price_min', 12, 2)->nullable();
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->date('price_date')->nullable();
            $table->foreignId('catalog_manual_sync_id')->nullable()->constrained('catalog_manual_syncs')->nullOnDelete();
            $table->timestamp('hold_until')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_manual_prices');
    }
};
