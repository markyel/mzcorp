<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * С какого импорта позиция снова в наличии. Журнала остатков нет, а
 * «поступления на склад» для обзора недели нужно утверждать честно: метку
 * ставит импорт каталога при переходе остатка 0 → больше нуля и снимает при
 * обнулении. Для уже лежащих на складе позиций она пустая — момент их
 * поступления неизвестен.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('catalog_items', 'in_stock_since')) {
            Schema::table('catalog_items', function (Blueprint $table) {
                $table->timestamp('in_stock_since')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('catalog_items', 'in_stock_since')) {
            Schema::table('catalog_items', function (Blueprint $table) {
                $table->dropIndex(['in_stock_since']);
                $table->dropColumn('in_stock_since');
            });
        }
    }
};
