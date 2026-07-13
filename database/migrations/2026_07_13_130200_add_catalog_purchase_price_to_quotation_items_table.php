<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Снапшот закупочной цены (себестоимости) позиции на момент попадания в КП.
 *
 * Нужен для режима cost_plus (Organization::pricing_mode): цена позиции =
 * catalog_purchase_price × (1 + наценка). Снапшотится всегда (и для standard-КП),
 * чтобы при смене режима не перечитывать каталог. Внутреннее поле — клиенту
 * не показывается. См. QuotationService::fillSnapshotFromCatalog / recalcTotals.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('quotation_items', 'catalog_purchase_price')) {
            Schema::table('quotation_items', function (Blueprint $table) {
                $table->decimal('catalog_purchase_price', 12, 2)->nullable()->after('catalog_price_min');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('quotation_items', 'catalog_purchase_price')) {
            Schema::table('quotation_items', function (Blueprint $table) {
                $table->dropColumn('catalog_purchase_price');
            });
        }
    }
};
