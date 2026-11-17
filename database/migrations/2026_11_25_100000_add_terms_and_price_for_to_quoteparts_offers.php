<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Условия цены из QuoteParts (17.11.2026): базис поставки («EXW Europe» — доставка и пошлина сверху) и за что цена,
 * когда не за единицу («1 кусок 135 м — 32 355 руб», канат Драко M24638 выглядел ценой метра). Идут в комментарий 1С.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quoteparts_offers')) {
            return;
        }
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('quoteparts_offers', 'incoterm')) {
                $table->string('incoterm', 10)->nullable();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'incoterm_place')) {
                $table->string('incoterm_place', 100)->nullable();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'price_for_qty')) {
                $table->decimal('price_for_qty', 12, 3)->nullable();
            }
            if (! Schema::hasColumn('quoteparts_offers', 'price_for_unit')) {
                $table->string('price_for_unit', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quoteparts_offers')) {
            return;
        }
        Schema::table('quoteparts_offers', function (Blueprint $table) {
            foreach (['incoterm', 'incoterm_place', 'price_for_qty', 'price_for_unit'] as $column) {
                if (Schema::hasColumn('quoteparts_offers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
