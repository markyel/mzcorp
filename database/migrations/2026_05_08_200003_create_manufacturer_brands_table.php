<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KB §3.3: бренды производителей оборудования.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturer_brands', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique();
            $table->jsonb('aliases')->default('[]');
            $table->jsonb('specialization_tags')->default('[]');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturer_brands');
    }
};
