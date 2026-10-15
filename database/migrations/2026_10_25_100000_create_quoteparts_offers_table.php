<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quoteparts_offers')) {
            // Копия офферов поставщиков из QuoteParts (ответы на запросы, прайсы) — для «Снабжения»:
            // цена поставщика видна у позиции как ответ. Источник истины — QuoteParts.
            Schema::create('quoteparts_offers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('remote_id')->unique();
                $table->foreignId('catalog_item_id')->nullable()->constrained('catalog_items')->nullOnDelete();
                $table->string('sku', 64)->nullable()->index();
                $table->string('supplier_key', 64)->nullable();
                $table->string('supplier_name')->nullable();
                $table->decimal('price', 14, 4)->nullable();
                $table->string('currency', 3)->nullable();
                $table->jsonb('tiers')->nullable();
                $table->unsignedSmallInteger('lead_time_days')->nullable();
                $table->date('valid_until')->nullable();
                $table->string('kind', 20)->nullable();
                $table->string('source_type', 20)->nullable();
                $table->string('status', 20)->nullable();
                $table->boolean('superseded')->default(false);
                $table->timestamp('remote_updated_at')->nullable();
                $table->timestamps();

                $table->index(['catalog_item_id', 'superseded']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quoteparts_offers');
    }
};
