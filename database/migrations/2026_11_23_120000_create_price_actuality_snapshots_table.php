<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Доля позиций прайса с актуальной ценой по дням (12.11.2026): растёт ли она от работы раздела QuoteParts.
 * Снимок раз в сутки после последней загрузки каталога (catalog:price-metrics-snapshot); прайс — активные
 * позиции каталога, «в наличии» — stock_available > 0, остальные — «под заказ».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('price_actuality_snapshots')) {
            return;
        }
        Schema::create('price_actuality_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_on')->unique();
            $table->unsignedInteger('active_total');
            $table->unsignedInteger('actual_total');
            $table->unsignedInteger('in_stock_total');
            $table->unsignedInteger('in_stock_actual');
            $table->unsignedInteger('to_order_total');
            $table->unsignedInteger('to_order_actual');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_actuality_snapshots');
    }
};
