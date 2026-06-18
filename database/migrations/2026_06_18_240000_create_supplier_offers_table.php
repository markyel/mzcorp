<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Предложение поставщика по позиции (Фаза 3.3, Foundation §4.2 SupplierOffer).
 * Создаётся из ответа поставщика на RFQ (ParseSupplierReplyJob): для каждой
 * запрошенной позиции — исход quoted (цена + опц. валюта + срок-как-текст) или
 * refused (причина). «Справочная информация для менеджера», не источник истины
 * каталога. Несколько офферов на позицию — по одному на каждый ответ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_offers')) {
            return;
        }
        Schema::create('supplier_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_inquiry_id')->constrained('supplier_inquiries')->cascadeOnDelete();
            $table->foreignId('supplier_inquiry_item_id')->nullable()->constrained('supplier_inquiry_items')->nullOnDelete();
            $table->foreignId('email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();
            $table->string('outcome', 16); // quoted | refused
            $table->decimal('price', 14, 2)->nullable();
            $table->string('currency', 16)->nullable();
            // Срок действия/поставки как написал поставщик, без интерпретации.
            $table->string('valid_until_text', 255)->nullable();
            $table->string('refusal_reason', 500)->nullable();
            // Цитата из ответа поставщика по этой позиции.
            $table->text('raw_quote')->nullable();
            $table->timestamps();

            $table->index(['supplier_inquiry_id', 'supplier_inquiry_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_offers');
    }
};
