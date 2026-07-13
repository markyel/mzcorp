<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Снапшот свободных остатков «в пути» на момент попадания позиции в КП.
 *
 * Формат как в catalog_items.stock_in_transit: [{qty:int, date:'Y-m-d'}]
 * (кол-во + дата прихода на склад). Нужен, чтобы при частичном/нулевом
 * наличии срок поставки дробился не только на «Со склада» + «Под заказ»,
 * но и на промежуточные приходы «Поставка к DD.MM.YYYY» (см.
 * QuotationItem::deliveryRows). Снапшотим для immutability исторических КП.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('quotation_items', 'catalog_stock_in_transit')) {
            Schema::table('quotation_items', function (Blueprint $table) {
                $table->jsonb('catalog_stock_in_transit')->nullable()->after('catalog_stock_available');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('quotation_items', 'catalog_stock_in_transit')) {
            Schema::table('quotation_items', function (Blueprint $table) {
                $table->dropColumn('catalog_stock_in_transit');
            });
        }
    }
};
