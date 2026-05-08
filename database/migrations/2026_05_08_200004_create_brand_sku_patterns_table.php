<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KB §3.4: маски артикулов производителей (regex без обрамляющих слешей).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_sku_patterns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('brand_id')
                ->constrained('manufacturer_brands')
                ->cascadeOnDelete();
            $table->string('pattern');
            $table->string('series_name')->nullable();
            $table->text('description')->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['brand_id', 'is_active']);
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_sku_patterns');
    }
};
