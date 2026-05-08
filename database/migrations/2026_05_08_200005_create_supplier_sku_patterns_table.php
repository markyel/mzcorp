<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KB §3.5: паттерны внутренних SKU поставщиков (например, LW-XXXXXXX).
 *
 * SKU поставщика НЕ передаются другим поставщикам в рассылках.
 *
 * MyLift adaptation: таблица `suppliers` ещё не существует (Phase 2.5+
 * supplier infra), поэтому FK на supplier_id оставляем как обычный
 * unsignedBigInteger без constrained. Когда suppliers появится — добавим
 * FK отдельной миграцией.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_sku_patterns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('pattern');
            $table->text('description')->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['supplier_id', 'is_active']);
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_sku_patterns');
    }
};
