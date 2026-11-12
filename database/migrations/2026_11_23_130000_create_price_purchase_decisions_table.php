<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Закупочные решения (12.11.2026): цена, выбранная «Закупаем здесь» и выгруженная в 1С с завершением сбора цен.
 * Журнал, а не текущее состояние: следующая цена позиции сравнивается с прошлым решением (или с закупкой 1С,
 * если она новее) — «Результаты» раздела QuoteParts (PriceDynamicsService::purchaseTrend).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('price_purchase_decisions')) {
            return;
        }
        Schema::create('price_purchase_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained('catalog_items')->cascadeOnDelete();
            $table->string('sku', 32);
            $table->string('supplier_key', 64)->nullable();
            $table->string('supplier_name')->nullable();
            $table->decimal('price', 14, 4);
            $table->string('currency', 8)->nullable();
            $table->string('source', 16)->nullable();
            $table->unsignedBigInteger('offer_ref')->nullable();
            $table->unsignedBigInteger('price_export_id')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->index();
            $table->timestamps();
            $table->index(['catalog_item_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_purchase_decisions');
    }
};
