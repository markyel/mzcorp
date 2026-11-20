<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Цена для 1С, заданная вручную у предложения поставщика (20.11.2026, UniSystem M16131: разбор взял 901,47 EUR — итог с
 * доставкой и НДС 19%, верная цена товара — 746,64). Выгрузка, выбор поставщика и сравнение — по ней; цена поставщика
 * остаётся как пришла.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('quoteparts_offers', 'manual_price')) {
                $table->decimal('manual_price', 14, 4)->nullable();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'manual_price_by_user_id')) {
                $table->foreignId('manual_price_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'manual_price_at')) {
                $table->timestamp('manual_price_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (Schema::hasColumn('quoteparts_offers', 'manual_price_by_user_id')) {
                $table->dropConstrainedForeignId('manual_price_by_user_id');
            }
            foreach (['manual_price', 'manual_price_at'] as $column) {
                if (Schema::hasColumn('quoteparts_offers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
