<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('quotation_items', 'catalog_stock_available')) {
            Schema::table('quotation_items', function (Blueprint $table) {
                // Снапшот свободного остатка (шт) на момент попадания позиции в КП.
                // Нужен, чтобы при ЧАСТИЧНОМ наличии (0 < сток < кол-во) разбить
                // срок на две под-строки: «Со склада» (из наличия) + «Под заказ»
                // (остаток). Раньше снапшотился только флаг catalog_in_stock.
                $table->integer('catalog_stock_available')->nullable()->after('catalog_in_stock');
            });
        }

        // Бэкфилл из текущего каталога для уже созданных позиций — исторические
        // КП начнут показывать срок корректно; новые снапшотят при создании.
        DB::statement(<<<'SQL'
            UPDATE quotation_items qi
            SET catalog_stock_available = ci.stock_available
            FROM catalog_items ci
            WHERE qi.catalog_item_id = ci.id
              AND qi.catalog_stock_available IS NULL
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasColumn('quotation_items', 'catalog_stock_available')) {
            Schema::table('quotation_items', function (Blueprint $table) {
                $table->dropColumn('catalog_stock_available');
            });
        }
    }
};
