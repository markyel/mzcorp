<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Позиции запроса расценки поставщику (Фаза 3.2). Какие RequestItem'ы мы
 * запросили у конкретного поставщика в рамках SupplierInquiry. status:
 * pending (ждём ответа) → quoted (есть SupplierOffer с ценой) | refused
 * (отказ) | cancelled (отменили). Аналог PriceRefreshItem (Foundation §4.5),
 * но привязан к SupplierInquiry (batch-якорь). Офферы — Фаза 3.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_inquiry_items')) {
            return;
        }
        Schema::create('supplier_inquiry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_inquiry_id')->constrained('supplier_inquiries')->cascadeOnDelete();
            $table->foreignId('request_item_id')->nullable()->constrained('request_items')->nullOnDelete();
            // Снимок названия позиции — на случай удаления/изменения RequestItem.
            $table->string('item_name', 500)->nullable();
            $table->string('status', 16)->default('pending'); // pending|quoted|refused|cancelled
            $table->timestamps();

            $table->index(['supplier_inquiry_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_inquiry_items');
    }
};
