<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quoteparts_item_declines')) {
            // Копия отметок поставщиков «не сможем предложить» из QuoteParts — для «Снабжения».
            // Источник истины — QuoteParts; снятая отметка — restored_at.
            Schema::create('quoteparts_item_declines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('remote_id')->unique();
                $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
                $table->string('sku', 64)->nullable()->index();
                $table->string('supplier_key', 64)->nullable()->index();
                $table->string('supplier_name')->nullable();
                $table->string('reason', 500)->nullable();
                $table->timestamp('declined_at')->nullable();
                $table->timestamp('restored_at')->nullable();
                $table->timestamp('remote_updated_at')->nullable();
                $table->timestamps();

                $table->index(['catalog_item_id', 'restored_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quoteparts_item_declines');
    }
};
