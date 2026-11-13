<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // M-артикул новой позиции (предварительная T-… в QuoteParts), когда он стал известен до появления
        // товара в каталоге: цены выгружаются в 1С под ним, а когда 1С выгрузит товар — позиция привязывается
        // к карточке (catalog_item_id, linked_at). Каталог не трогаем.
        if (Schema::hasTable('price_draft_skus')) {
            return;
        }
        Schema::create('price_draft_skus', function (Blueprint $table) {
            $table->id();
            $table->string('draft_code', 32)->unique();
            $table->string('sku', 32)->index();
            $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_draft_skus');
    }
};
